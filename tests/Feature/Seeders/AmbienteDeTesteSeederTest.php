<?php

namespace Tests\Feature\Seeders;

use App\Enums\ChannelStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\NoteOrigin;
use App\Enums\SectorStatus;
use App\Enums\TagScope;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Setup\DefaultSectors;
use Database\Seeders\AmbienteDeTesteSeeder;
use Database\Seeders\Cenarios\EtiquetasDosDoisEscopos;
use Database\Seeders\Cenarios\UsuariosDeTeste;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Event;
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

    public function test_usuarios_de_teste_sozinho_tem_setores(): void
    {
        $this->seed(UsuariosDeTeste::class);

        $admin = User::query()->where('email', AmbienteDeTesteSeeder::ADMIN_EMAIL)->firstOrFail();
        $atendente = User::query()->where('email', AmbienteDeTesteSeeder::ATENDENTE_EMAIL)->firstOrFail();
        $this->assertGreaterThan(2, Sector::query()->count());
        $this->assertSame(Sector::query()->count(), $admin->sectors()->count());
        $this->assertSame(2, $atendente->sectors()->count());
    }

    public function test_usuarios_de_teste_sozinho_cria_setores_mesmo_com_a_marca(): void
    {
        Setting::put(DefaultSectors::MARK, true);

        $this->seed(UsuariosDeTeste::class);

        $this->assertEqualsCanonicalizing(DefaultSectors::NAMES, Sector::query()->where('status', SectorStatus::ATIVO)->pluck('name')->all());
        $atendente = User::query()->where('email', AmbienteDeTesteSeeder::ATENDENTE_EMAIL)->firstOrFail();
        $this->assertSame(2, $atendente->sectors()->where('status', SectorStatus::ATIVO)->count());
    }

    public function test_usuarios_de_teste_reativa_setor_apagado_ou_desativado_pelo_nome(): void
    {
        Setting::put(DefaultSectors::MARK, true);
        Sector::factory()->create(['name' => 'Vendas', 'status' => SectorStatus::DESATIVADO])->delete();
        Sector::factory()->create(['name' => 'SAC', 'status' => SectorStatus::DESATIVADO]);

        $this->seed(UsuariosDeTeste::class);

        $this->assertEqualsCanonicalizing(DefaultSectors::NAMES, Sector::query()->where('status', SectorStatus::ATIVO)->pluck('name')->all());
        $this->assertSame(5, Sector::withTrashed()->count());
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
        $contatoEtiquetado = Contact::query()->where('name', EtiquetasDosDoisEscopos::NOME_DO_CONTATO)->firstOrFail();
        $this->assertTrue($contatoEtiquetado->tags()->exists());
        $this->assertTrue(Tag::query()->where('scope', TagScope::CONTATO)->whereNotIn('id', $contatoEtiquetado->tags()->pluck('tags.id'))->exists());
        $ticketEtiquetado = $contatoEtiquetado->tickets()->firstOrFail();
        $this->assertTrue($ticketEtiquetado->tags()->exists());
        $this->assertTrue(Tag::query()->where('scope', TagScope::CONVERSA)->whereNotIn('id', $ticketEtiquetado->tags()->pluck('tags.id'))->exists());

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

    public function test_alertas_do_sino_gravam_so_no_banco_sem_broadcast(): void
    {
        Event::fake([BroadcastNotificationCreated::class]);

        $this->seed(AmbienteDeTesteSeeder::class);

        Event::assertNotDispatched(BroadcastNotificationCreated::class);
        $this->assertSame(3, User::query()->where('email', AmbienteDeTesteSeeder::ADMIN_EMAIL)->firstOrFail()->notifications()->count());
    }

    public function test_canal_parado_nao_e_reaproveitado(): void
    {
        Channel::factory()->create();

        $this->seed(AmbienteDeTesteSeeder::class);

        $this->assertTrue(Channel::query()->where('status', ChannelStatus::WORKING)->exists());
    }

    public function test_oi_ok_e_citacao_ficam_em_ticket_aberto_e_apontam_para_a_mensagem_certa(): void
    {
        $this->seed(AmbienteDeTesteSeeder::class);

        $admin = User::query()->where('email', AmbienteDeTesteSeeder::ADMIN_EMAIL)->firstOrFail();
        foreach (['Oi', 'Ok'] as $corpo) {
            $ticket = Message::query()->where('body', $corpo)->firstOrFail()->ticket;
            $this->assertSame(TicketStatus::EM_ATENDIMENTO, $ticket->status, $corpo);
            $this->assertSame($admin->id, $ticket->assigned_user_id, $corpo);
        }
        $citacao = Message::query()->whereNotNull('quoted_wa_message_id')->firstOrFail();
        $alvo = Message::query()->where('wa_message_id', $citacao->quoted_wa_message_id)->firstOrFail();
        $this->assertSame($citacao->ticket_id, $alvo->ticket_id);
        $this->assertGreaterThanOrEqual(500, mb_strlen($alvo->body));
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

    public function test_cada_cenario_recusa_rodar_em_producao(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        foreach (glob(database_path('seeders/Cenarios/*.php')) as $arquivo) {
            $classe = (new \ReflectionClass(UsuariosDeTeste::class))->getNamespaceName().'\\'.basename($arquivo, '.php');
            try {
                $this->app->make($classe)->run();
                $this->fail($classe.' deveria recusar em produção.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('local', $e->getMessage(), $classe);
            }
        }

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Channel::query()->count());
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_tem_conversa_da_automacao(): void
    {
        $this->seed(AmbienteDeTesteSeeder::class);

        $resposta = Message::query()->where('origin', MessageOrigin::BOT)->firstOrFail();
        $nota = TicketNote::query()->where('origin', NoteOrigin::BOT)->firstOrFail();
        $this->assertSame($resposta->ticket_id, $nota->ticket_id);
        $this->assertNull($nota->user_id);
        $this->assertTrue(Message::query()->where('ticket_id', $resposta->ticket_id)->where('direction', MessageDirection::IN)->exists());
    }

    public function test_cria_tickets_com_sla_estourado_e_a_vencer(): void
    {
        $this->seed(AmbienteDeTesteSeeder::class);

        $abertos = Ticket::query()->where('status', TicketStatus::AGUARDANDO)->whereNotNull('waiting_since')->whereNotNull('sla_due_at');
        $this->assertTrue((clone $abertos)->where('sla_due_at', '<', now())->exists(), 'prazo estourado');
        $this->assertTrue((clone $abertos)->where('sla_due_at', '>', now())->exists(), 'prazo a vencer');
    }
}
