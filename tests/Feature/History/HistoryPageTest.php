<?php

namespace Tests\Feature\History;

use App\Enums\CloseOutcome;
use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\History\Index;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Inbox\SendFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class HistoryPageTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    private Sector $mine;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::factory()->create();
        $this->mine = Sector::factory()->create();
        $this->agent = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->agent->sectors()->attach($this->mine);
    }

    private function closed(string $name, array $override = []): Ticket
    {
        return Ticket::factory()->create(array_replace([
            'channel_id' => $this->channel->id,
            'contact_id' => Contact::factory()->create(['name' => $name])->id,
            'status' => TicketStatus::ENCERRADO,
            'closed_at' => now()->subHour(),
            'sector_id' => $this->mine->id,
        ], $override));
    }

    private function page(?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->agent)->test(Index::class);
    }

    public function test_exige_login_e_todos_os_papeis_entram(): void
    {
        $this->get('/historico')->assertRedirect('/login');

        foreach (UserRole::cases() as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/historico')->assertOk();
        }
    }

    public function test_lista_so_o_que_o_usuario_pode_ver_e_ordena_pelo_encerramento(): void
    {
        $this->closed('Mais Antigo', ['closed_at' => now()->subDays(3)]);
        $this->closed('Mais Novo', ['closed_at' => now()->subDay()]);
        $this->closed('Outro Setor', ['sector_id' => Sector::factory()->create()->id]);
        Ticket::factory()->create(['channel_id' => $this->channel->id, 'status' => TicketStatus::AGUARDANDO, 'sector_id' => $this->mine->id]);

        $this->page()->assertSeeInOrder(['Mais Novo', 'Mais Antigo'])->assertDontSee('Outro Setor');
    }

    public function test_busca_filtra_por_contato_protocolo_e_texto(): void
    {
        $joaquim = $this->closed('Joaquim Pereira');
        $maria = $this->closed('Maria Souza');
        Message::factory()->for($maria)->create(['body' => 'preciso de cotacao de inox']);

        $this->page()->set('q', 'joaquim')->assertSee('Joaquim Pereira')->assertDontSee('Maria Souza');
        $this->page()->set('q', (string) $maria->protocol)->assertSee('Maria Souza')->assertDontSee('Joaquim Pereira');
        $this->page()->set('q', 'cotacao')->assertSee('Maria Souza')->assertDontSee('Joaquim Pereira');
        $this->assertNotNull($joaquim->id);
    }

    public function test_filtros_de_periodo_setor_e_motivo(): void
    {
        $other = Sector::factory()->create();
        $this->agent->sectors()->attach($other);
        $this->closed('Resolvido Antigo', ['closed_at' => now()->subDays(40), 'close_outcome' => CloseOutcome::RESOLVIDO]);
        $this->closed('Resolvido Recente', ['close_outcome' => CloseOutcome::RESOLVIDO]);
        $this->closed('Sem Resposta', ['sector_id' => $other->id, 'close_outcome' => CloseOutcome::SEM_RESPOSTA]);

        $this->page()->assertSee('Resolvido Recente')->assertSee('Sem Resposta')->assertDontSee('Resolvido Antigo');
        $this->page()->set('from', '')->assertSee('Resolvido Antigo');
        $this->page()->set('outcome', 'sem_resposta')->assertSee('Sem Resposta')->assertDontSee('Resolvido Recente');
        $this->page()->set('sectorId', $this->mine->id)->assertDontSee('Sem Resposta')->assertSee('Resolvido Recente');
    }

    public function test_filtro_de_atendente_so_aparece_para_supervisor_e_admin(): void
    {
        $this->page()->assertDontSee('Atendente');
        $this->page(User::factory()->create(['role' => UserRole::SUPERVISOR]))->assertSee('Atendente');
    }

    public function test_selecionar_mostra_transcricao_eventos_e_resumo_sem_reenviar_nem_compositor(): void
    {
        $closer = User::factory()->create(['role' => UserRole::ATENDENTE, 'name' => 'Marina Alves']);
        $ticket = $this->closed('Joaquim', ['assigned_user_id' => $closer->id, 'close_outcome' => CloseOutcome::RESOLVIDO, 'close_summary' => 'Cotacao aprovada pelo socio']);
        Message::factory()->for($ticket)->create(['body' => 'Pode fechar o pedido']);
        Message::factory()->for($ticket)->create([
            'direction' => MessageDirection::OUT, 'origin' => MessageOrigin::PAINEL, 'author_user_id' => $closer->id,
            'wa_message_id' => null, 'body' => 'Falhou aqui', 'delivery_status' => DeliveryStatus::FAILED, 'failure_code' => SendFailure::EXPIRED,
        ]);
        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'claimed', 'actor_user_id' => $closer->id]);
        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'closed', 'actor_user_id' => $closer->id, 'payload' => ['outcome' => 'resolvido', 'summary' => 'Cotacao aprovada pelo socio']]);

        $this->page($closer)->call('select', $ticket->id)
            ->assertSee('Pode fechar o pedido')
            ->assertSee('Assumido')
            ->assertSee('Cotacao aprovada pelo socio')
            ->assertSee('Marina Alves')
            ->assertSee((string) $ticket->protocol)
            ->assertDontSee('Reenviar')
            ->assertDontSeeHtml('wire:submit="send"');
    }

    public function test_ticket_de_outro_setor_responde_403_ao_selecionar_e_na_midia(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('outbound/x.jpg', 'x');
        $foreign = $this->closed('Outro Setor', ['sector_id' => Sector::factory()->create()->id]);
        $media = Message::factory()->for($foreign)->create(['type' => 'media', 'media_mime' => 'image/jpeg', 'local_path' => 'outbound/x.jpg']);

        $this->page()->call('select', $foreign->id)->assertForbidden();
        $this->actingAs($this->agent)->get(route('midia', $media))->assertForbidden();
    }

    public function test_ticket_aberto_nao_abre_pelo_historico(): void
    {
        $open = Ticket::factory()->create(['channel_id' => $this->channel->id, 'status' => TicketStatus::AGUARDANDO, 'sector_id' => $this->mine->id]);

        $this->page()->call('select', $open->id)->assertNotFound();
    }

    public function test_avisa_quando_a_conversa_tem_mais_de_200_mensagens(): void
    {
        $ticket = $this->closed('Conversa Longa');
        Message::factory()->count(201)->for($ticket)->create();

        $this->page()->call('select', $ticket->id)->assertSee('últimas 200 mensagens');
    }

    public function test_anexo_expirado_aparece_como_expirado(): void
    {
        $ticket = $this->closed('Com Anexo');
        Message::factory()->for($ticket)->create(['type' => 'media', 'body' => '', 'media_mime' => 'image/jpeg', 'media_ref' => null, 'local_path' => null, 'media_purged_at' => now()]);

        $this->page()->call('select', $ticket->id)->assertSee('Arquivo expirado');
    }

    public function test_a_lista_nao_faz_consulta_por_linha(): void
    {
        $countQueries = function (int $rows): int {
            Ticket::query()->delete();
            foreach (range(1, $rows) as $i) {
                $this->closed('Cliente '.$i, ['assigned_user_id' => $this->agent->id]);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->page(User::factory()->create(['role' => UserRole::SUPERVISOR]));
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $this->assertSame($countQueries(3), $countQueries(20));
    }
}
