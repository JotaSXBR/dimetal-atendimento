<?php

namespace Tests\Feature\Seeders;

use App\Enums\ChannelStatus;
use App\Enums\MessageDirection;
use App\Enums\TagScope;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Database\Seeders\AmbienteDeTesteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AmbienteDeTesteSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_os_usuarios_de_teste(): void
    {
        $this->seed(AmbienteDeTesteSeeder::class);

        $admin = User::query()->where('email', AmbienteDeTesteSeeder::ADMIN_EMAIL)->firstOrFail();
        $atendente = User::query()->where('email', AmbienteDeTesteSeeder::ATENDENTE_EMAIL)->firstOrFail();

        $this->assertSame(Sector::query()->count(), $admin->sectors()->count());
        $this->assertSame(2, $atendente->sectors()->count());
        $this->assertTrue($atendente->active);
        $this->assertTrue(Hash::check(AmbienteDeTesteSeeder::SENHA, $atendente->password));
        $this->assertTrue(Hash::check(AmbienteDeTesteSeeder::SENHA, $admin->password));
    }

    public function test_cobre_cada_cenario(): void
    {
        $this->seed(AmbienteDeTesteSeeder::class);
        $admin = User::query()->where('email', AmbienteDeTesteSeeder::ADMIN_EMAIL)->firstOrFail();

        $this->assertSame(ChannelStatus::WORKING, Channel::query()->firstOrFail()->status);
        $this->assertNotNull(Channel::query()->firstOrFail()->phone);

        foreach ([TicketStatus::PENDENTE, TicketStatus::AGUARDANDO, TicketStatus::ENCERRADO] as $status) {
            $this->assertTrue(Ticket::query()->where('status', $status)->exists(), $status->value);
        }
        $this->assertTrue(Ticket::query()->where('status', TicketStatus::EM_ATENDIMENTO)->where('assigned_user_id', $admin->id)->exists());

        $longo = Contact::query()->whereRaw('char_length(name) >= 60')->firstOrFail();
        $this->assertGreaterThanOrEqual(1, $longo->tickets()->where('status', '!=', TicketStatus::ENCERRADO)->count());

        $this->assertTrue(Message::query()->where('direction', MessageDirection::IN)->where('body', 'Oi')->exists());
        $this->assertTrue(Message::query()->where('direction', MessageDirection::OUT)->where('body', 'Ok')->exists());
        $this->assertTrue(Message::query()->whereRaw('char_length(body) >= 500')->exists());
        $citada = Message::query()->whereNotNull('quoted_wa_message_id')->firstOrFail();
        $this->assertNotNull($citada->quoted_snapshot);

        $this->assertTrue(Contact::query()->whereHas('tags', fn ($q) => $q->where('scope', TagScope::CONTATO))->exists());
        $this->assertTrue(Ticket::query()->whereHas('tags', fn ($q) => $q->where('scope', TagScope::CONVERSA))->exists());
        $this->assertGreaterThan(1, Tag::query()->where('scope', TagScope::CONTATO)->count());
        $this->assertGreaterThan(1, Tag::query()->where('scope', TagScope::CONVERSA)->count());

        $this->assertTrue(TicketNote::query()->whereHas('ticket', fn ($q) => $q->where('status', '!=', TicketStatus::ENCERRADO))->exists());

        $alertas = $admin->notifications()->get();
        $this->assertSame(3, $alertas->count());
        $sla = $alertas->filter(fn ($n) => $n->data['kind'] === 'sla_breached');
        $this->assertSame(2, $sla->count());
        $status = $sla->map(fn ($n) => Ticket::query()->findOrFail($n->data['ticket_id'])->status)->all();
        $this->assertContains(TicketStatus::ENCERRADO, $status);
        $this->assertNotEmpty(array_filter($status, fn ($s) => $s !== TicketStatus::ENCERRADO));
        $recuperacao = $alertas->firstWhere('data.kind', 'recovery');
        $this->assertSame(0, $recuperacao->data['messages']);
        $this->assertSame('recovered', $recuperacao->data['state']);
    }

    public function test_rodar_duas_vezes_nao_duplica_usuarios_nem_canal(): void
    {
        $this->seed(AmbienteDeTesteSeeder::class);
        $this->seed(AmbienteDeTesteSeeder::class);

        $this->assertSame(1, User::query()->where('email', AmbienteDeTesteSeeder::ADMIN_EMAIL)->count());
        $this->assertSame(1, User::query()->where('email', AmbienteDeTesteSeeder::ATENDENTE_EMAIL)->count());
        $this->assertSame(1, Channel::query()->count());
    }

    public function test_recusa_rodar_em_producao(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        try {
            $this->app->make(AmbienteDeTesteSeeder::class)->run();
            $this->fail('Deveria recusar em produção.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('local', $e->getMessage());
        }

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Sector::query()->count());
    }
}
