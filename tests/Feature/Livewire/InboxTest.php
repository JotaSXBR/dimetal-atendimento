<?php

namespace Tests\Feature\Livewire;

use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Jobs\SendTicketMessageJob;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::factory()->create();
    }

    private function user(UserRole $role = UserRole::ATENDENTE): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function ticket(TicketStatus $status = TicketStatus::AGUARDANDO, ?User $owner = null, string $name = 'Maria Souza'): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => $this->channel->id,
            'contact_id' => Contact::factory()->create(['name' => $name]),
            'status' => $status,
            'assigned_user_id' => $owner?->id,
        ]);
    }

    public function test_visitante_vai_para_o_login(): void
    {
        $this->get(route('inbox'))->assertRedirect(route('login'));
    }

    public function test_lista_mostra_so_o_que_o_usuario_pode_ver(): void
    {
        $me = $this->user();
        $this->ticket(name: 'Na Fila');
        $this->ticket(TicketStatus::EM_ATENDIMENTO, $me, 'Meu Cliente');
        $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->user(), 'Cliente Alheio');

        Livewire::actingAs($me)->test(Inbox::class)
            ->assertSee('Na Fila')
            ->assertSee('Meu Cliente')
            ->assertDontSee('Cliente Alheio');
    }

    public function test_abrir_ticket_de_outro_atendente_responde_403(): void
    {
        $alheio = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->user());

        Livewire::actingAs($this->user())->test(Inbox::class)
            ->call('select', $alheio->id)
            ->assertForbidden();
    }

    public function test_assumir_move_o_ticket_para_meus(): void
    {
        $me = $this->user();
        $ticket = $this->ticket();

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('select', $ticket->id)
            ->call('claim')
            ->set('tab', 'meus')
            ->assertSee('Maria Souza');

        $this->assertSame($me->id, $ticket->fresh()->assigned_user_id);
    }

    public function test_dois_usuarios_assumindo_o_mesmo_ticket_apenas_um_vence_e_o_outro_ve_aviso(): void
    {
        [$a, $b] = [$this->user(), $this->user()];
        $ticket = $this->ticket();

        $lento = Livewire::actingAs($b)->test(Inbox::class)->call('select', $ticket->id);
        Livewire::actingAs($a)->test(Inbox::class)->call('select', $ticket->id)->call('claim');

        $lento->call('claim')->assertSee('Outra pessoa assumiu');
        $this->assertSame($a->id, $ticket->fresh()->assigned_user_id);
    }

    public function test_enviar_despacha_job_e_limpa_rascunho(): void
    {
        Queue::fake();
        $me = $this->user();
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $me);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('select', $ticket->id)
            ->set('draft', '  Olá!  ')
            ->call('send')
            ->assertSet('draft', '');

        $message = Message::query()->sole();
        $this->assertSame('Olá!', $message->body);
        $this->assertNull($message->delivery_status);
        Queue::assertPushedOn('outbound', SendTicketMessageJob::class, fn ($job) => $job->messageId === $message->id);
    }

    public function test_previa_da_lista_nomeia_midia_e_visualizacao_unica(): void
    {
        $me = $this->user();
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $me);

        foreach ([
            [['type' => 'media', 'body' => '', 'media_mime' => 'image/jpeg'], 'Foto'],
            [['type' => 'media', 'body' => '', 'media_mime' => 'image/webp'], 'Figurinha'],
            [['type' => 'media', 'body' => '', 'media_mime' => 'audio/ogg'], 'Áudio'],
            [['type' => 'media', 'body' => '', 'media_mime' => 'video/mp4'], 'Vídeo'],
            [['type' => 'media', 'body' => '', 'media_mime' => 'application/pdf'], 'Arquivo'],
            [['type' => 'view_once', 'body' => ''], 'Visualização única'],
            [['type' => 'location', 'body' => '-21.17,-47.81'], 'Localização'],
            [['type' => 'contact', 'body' => 'Contato: Maria (+55 16 99999-0001)'], 'Contato'],
            [['type' => 'media', 'body' => 'a legenda', 'media_mime' => 'image/jpeg'], 'a legenda'],
        ] as [$attributes, $expected]) {
            Message::factory()->for($ticket)->create($attributes);

            Livewire::actingAs($me)->test(Inbox::class)->assertSee($expected);
        }
    }

    public function test_enviar_texto_vazio_e_rejeitado(): void
    {
        Queue::fake();
        $me = $this->user();
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $me);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('select', $ticket->id)
            ->set('draft', '   ')
            ->call('send')
            ->assertHasErrors('draft');

        Queue::assertNothingPushed();
    }

    public function test_atendente_nao_responde_ticket_nao_assumido(): void
    {
        Queue::fake();
        $ticket = $this->ticket();

        Livewire::actingAs($this->user())->test(Inbox::class)
            ->call('select', $ticket->id)
            ->set('draft', 'oi')
            ->call('send')
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_encerrar_remove_da_fila_e_mostra_somente_leitura(): void
    {
        $me = $this->user();
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $me);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('select', $ticket->id)
            ->call('close')
            ->assertSee('Somente leitura')
            ->assertDontSee('Escreva uma resposta');

        $this->assertSame(TicketStatus::ENCERRADO, $ticket->fresh()->status);
    }

    public function test_mensagem_fora_do_painel_mostra_o_selo(): void
    {
        $me = $this->user();
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $me);
        Message::factory()->for($ticket)->create(['origin' => MessageOrigin::FORA_DO_PAINEL, 'body' => 'pelo celular']);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('select', $ticket->id)
            ->assertSee('fora do painel')
            ->assertSee('pelo celular');
    }
}
