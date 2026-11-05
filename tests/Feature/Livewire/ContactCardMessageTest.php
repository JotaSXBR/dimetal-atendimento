<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Transcript;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ContactCardMessageTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = "Contato: Maria Souza (+55 16 99999-0001)\nContato: Beto (+55 11 90000-0002)\nContato: Sem Fone";

    private User $me;

    private Ticket $ticket;

    private Message $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->ticket = Ticket::factory()->create(['channel_id' => Channel::factory()->create()->id, 'status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $this->me->id]);
        $this->card = Message::factory()->for($this->ticket)->create(['type' => 'contact', 'body' => self::BODY, 'wa_message_id' => 'W1']);
    }

    private function screen(): Testable
    {
        return Livewire::actingAs($this->me)->test(Transcript::class, ['ticketId' => $this->ticket->id]);
    }

    public function test_cartao_tem_adicionar_e_conversar_no_lugar_do_link_wa_me(): void
    {
        $this->screen()
            ->assertSee('Adicionar aos contatos')
            ->assertSee('Conversar')
            ->assertDontSeeHtml('wa.me');
    }

    public function test_cartao_sem_telefone_nao_tem_os_botoes(): void
    {
        $solo = Message::factory()->for($this->ticket)->create(['type' => 'contact', 'body' => 'Contato: Sem Fone', 'wa_message_id' => 'W2']);
        $this->card->delete();

        $this->screen()->assertSee('Sem Fone')->assertDontSee('Adicionar aos contatos')->assertDontSee('Conversar');
        $this->assertNotNull($solo);
    }

    public function test_adicionar_cria_o_contato_sem_duplicar_e_o_cartao_passa_a_dizer_que_ja_esta(): void
    {
        $screen = $this->screen()->call('addContact', $this->card->id, 0);

        $contact = Contact::query()->where('wa_id', '5516999990001@c.us')->firstOrFail();
        $this->assertSame('Maria Souza', $contact->name);

        $screen->call('addContact', $this->card->id, 0)->assertSee('Já está nos contatos');
        $this->assertSame(1, Contact::query()->where('wa_id', '5516999990001@c.us')->count());
    }

    public function test_numero_com_e_sem_nono_digito_e_o_mesmo_contato(): void
    {
        $existing = Contact::factory()->create(['wa_id' => '551100000002@c.us']);
        $before = Contact::query()->count();

        $this->screen()->call('addContact', $this->card->id, 1);

        $this->assertSame($before, Contact::query()->count());
        $this->assertNotNull($existing);
    }

    public function test_mensagem_de_outro_ticket_e_ignorada(): void
    {
        $other = Ticket::factory()->create(['channel_id' => $this->ticket->channel_id, 'status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $this->me->id]);
        $foreign = Message::factory()->for($other)->create(['type' => 'contact', 'body' => 'Contato: Intruso (+55 16 98888-0000)', 'wa_message_id' => 'W3']);
        $before = Contact::query()->count();

        $this->screen()->call('addContact', $foreign->id, 0)->call('chatWith', $foreign->id, 0);

        $this->assertSame($before, Contact::query()->count());
    }

    public function test_conversar_garante_o_contato_e_abre_a_janela(): void
    {
        $this->screen()->call('chatWith', $this->card->id, 0);

        $contact = Contact::query()->where('wa_id', '5516999990001@c.us')->firstOrFail();

        $this->screen()->call('chatWith', $this->card->id, 0)->assertDispatched('start-conversation', contactId: $contact->id);
    }
}
