<?php

namespace Tests\Feature\Google;

use App\Actions\Contacts\CreateContact;
use App\Actions\Contacts\SaveContact;
use App\Actions\Contacts\SyncPendingContacts;
use App\Enums\GoogleConnectionStatus;
use App\Enums\UserRole;
use App\Jobs\SyncContactToGoogleJob;
use App\Models\Contact;
use App\Models\GoogleConnection;
use App\Models\User;
use App\Services\Inbox\ResolveContact;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleSyncTriggersTest extends TestCase
{
    use RefreshDatabase;

    private function connect(GoogleConnectionStatus $status = GoogleConnectionStatus::CONNECTED): void
    {
        GoogleConnection::query()->create([
            'account_email' => 'dimetal@gmail.com', 'access_token' => 'tok', 'refresh_token' => 'ref',
            'expires_at' => now()->addHour(), 'status' => $status, 'connected_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function pending(array $attributes = []): Contact
    {
        return Contact::factory()->edited()->create($attributes);
    }

    private function assertQueuedFor(Contact $contact): void
    {
        Queue::assertPushedOn('google', SyncContactToGoogleJob::class, fn (SyncContactToGoogleJob $job): bool => $job->contactId === $contact->id);
    }

    public function test_salvar_contato_enfileira_o_envio_so_com_google_conectado(): void
    {
        Queue::fake();
        $contact = Contact::factory()->create();

        app(SaveContact::class)($contact, ['name' => 'Sem conexao']);
        Queue::assertNothingPushed();

        $this->connect();
        app(SaveContact::class)($contact, ['name' => 'Com conexao']);
        $this->assertQueuedFor($contact);
    }

    public function test_cadastro_manual_tambem_enfileira(): void
    {
        Queue::fake();
        $this->connect();

        $result = app(CreateContact::class)('(16) 99752-4950', 'Ana');

        $this->assertQueuedFor($result['contact']);
    }

    public function test_contato_que_entra_por_mensagem_sem_edicao_nao_enfileira_nada(): void
    {
        Queue::fake();
        $this->connect();

        (new ResolveContact)('5516997524950@c.us', null, 'Push Name');

        Queue::assertNothingPushed();
    }

    public function test_job_e_unico_por_contato_e_nao_prende_a_proxima_edicao(): void
    {
        $job = new SyncContactToGoogleJob(7);

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame((new SyncContactToGoogleJob(7))->uniqueId(), $job->uniqueId());
        $this->assertNotSame((new SyncContactToGoogleJob(8))->uniqueId(), $job->uniqueId());
        $this->assertSame(1, $job->tries);
    }

    public function test_job_executa_a_sincronizacao_e_ignora_contato_apagado(): void
    {
        $this->connect();
        Http::fake([
            '*:searchContacts*' => Http::response([], 200),
            '*:createContact*' => Http::response(['resourceName' => 'people/c9', 'metadata' => ['sources' => [['type' => 'CONTACT', 'etag' => 'E9']]]], 200),
        ]);
        $contact = $this->pending(['wa_id' => '5516997524950@c.us']);

        SyncContactToGoogleJob::dispatchSync($contact->id);
        SyncContactToGoogleJob::dispatchSync(999999);

        $this->assertSame('people/c9', $contact->fresh()?->google_resource_name);
    }

    public function test_o_callback_do_oauth_enfileira_os_contatos_ja_editados(): void
    {
        Queue::fake();
        config(['services.google' => ['client_id' => 'cid', 'client_secret' => 'secret', 'redirect' => 'http://localhost/cb']]);
        $waiting = $this->pending();
        $user = (new SocialiteUser)->map(['email' => 'dimetal@gmail.com']);
        $user->token = 'access';
        $user->refreshToken = 'refresh';
        $user->expiresIn = 3600;
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->get('/configuracoes/google/callback?code=abc');

        $this->assertQueuedFor($waiting);
    }

    public function test_varredura_enfileira_pendentes_e_ignora_sincronizados_erros_recentes_e_inelegiveis(): void
    {
        Queue::fake();
        $this->connect();
        $now = now();
        $never = $this->pending();
        $changed = $this->pending(['google_resource_name' => 'people/c1', 'google_synced_at' => $now->subHour(), 'updated_at' => $now]);
        $oldError = $this->pending(['google_error' => 'rate_limited', 'google_attempted_at' => $now->subHours(2)]);
        $this->pending(['google_resource_name' => 'people/c2', 'google_synced_at' => $now, 'updated_at' => $now]);
        $this->pending(['google_error' => 'rate_limited', 'google_attempted_at' => $now->subMinutes(10)]);
        Contact::factory()->create();
        $this->pending(['wa_id' => null, 'lid' => '1@lid']);

        $count = app(SyncPendingContacts::class)();

        $this->assertSame(3, $count);
        foreach ([$never, $changed, $oldError] as $contact) {
            $this->assertQueuedFor($contact);
        }
        Queue::assertPushed(SyncContactToGoogleJob::class, 3);
    }

    public function test_varredura_nao_faz_nada_sem_conexao_ativa(): void
    {
        Queue::fake();
        $this->pending();

        $this->assertSame(0, app(SyncPendingContacts::class)());

        $this->connect(GoogleConnectionStatus::NEEDS_REAUTH);
        $this->assertSame(0, app(SyncPendingContacts::class)());
        Queue::assertNothingPushed();
    }

    public function test_comando_roda_a_varredura_e_conta(): void
    {
        Queue::fake();
        $this->connect();
        $this->pending();

        $this->artisan('app:sync-google-contacts')->expectsOutputToContain('1 contato')->assertSuccessful();
    }

    public function test_edicao_no_mesmo_segundo_do_envio_continua_pendente(): void
    {
        Queue::fake();
        $this->connect();
        $this->travelTo(now()->startOfSecond());
        $contact = $this->pending(['google_resource_name' => 'people/c1', 'google_synced_at' => now(), 'updated_at' => now()]);
        $this->assertSame(0, Contact::query()->pendingGoogle()->count());

        app(SaveContact::class)($contact->fresh() ?? $contact, ['name' => 'Editado no mesmo segundo']);

        $this->assertSame(1, Contact::query()->pendingGoogle()->count());
    }

    public function test_varredura_agendada_a_cada_10_minutos_sem_sobreposicao(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'app:sync-google-contacts'));

        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_sucesso_deixa_o_contato_fora_da_varredura_seguinte(): void
    {
        $this->connect();
        Http::fake([
            '*:searchContacts*' => Http::response([], 200),
            '*:createContact*' => Http::response(['resourceName' => 'people/c9', 'metadata' => ['sources' => [['type' => 'CONTACT', 'etag' => 'E9']]]], 200),
        ]);
        $contact = $this->pending();
        $this->assertSame(1, Contact::query()->pendingGoogle()->count());

        SyncContactToGoogleJob::dispatchSync($contact->id);

        $this->assertSame(0, Contact::query()->pendingGoogle()->count());

        $this->travel(2)->seconds();
        $contact->fresh()?->forceFill(['name' => 'Mudou'])->save();
        $this->assertSame(1, Contact::query()->pendingGoogle()->count(), 'edição depois do envio volta a ser pendente');
    }
}
