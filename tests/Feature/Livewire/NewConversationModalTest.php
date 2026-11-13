<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Jobs\SendTicketMessageJob;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Inbox\NewConversation;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Settings\CompanySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class NewConversationModalTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    private Sector $sector;

    private User $agent;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        $this->fakeCheck(true);

        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $this->sector = Sector::factory()->create(['name' => 'Vendas']);
        CompanySettings::save(['signatureEnabled' => false]);
        $this->agent = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->agent->sectors()->attach($this->sector);
        $this->contact = Contact::factory()->create(['wa_id' => '5516999999999@c.us', 'name' => 'Maria Souza']);
    }

    private function fakeCheck(bool $exists): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*/api/contacts/check-exists*' => Http::response(['numberExists' => $exists])]);
    }

    public function test_abrir_com_contato_preseleciona_o_unico_canal_e_o_unico_setor(): void
    {
        Livewire::actingAs($this->agent)->test(NewConversation::class)
            ->dispatch('start-conversation', contactId: $this->contact->id)
            ->assertSet('contactId', $this->contact->id)
            ->assertSet('channelId', $this->channel->id)
            ->assertSet('sectorId', $this->sector->id);
    }

    public function test_canal_do_ticket_mais_recente_do_contato_vem_preselecionado(): void
    {
        $other = Channel::factory()->connected()->create(['waha_session' => 'outro']);
        Ticket::factory()->create(['contact_id' => $this->contact->id, 'channel_id' => $other->id, 'status' => TicketStatus::ENCERRADO, 'opened_at' => now()->subDay()]);

        Livewire::actingAs($this->agent)->test(NewConversation::class)
            ->dispatch('start-conversation', contactId: $this->contact->id)
            ->assertSet('channelId', $other->id);
    }

    public function test_atendente_com_dois_setores_escolhe_e_nao_forja_outro(): void
    {
        $second = Sector::factory()->create(['name' => 'Financeiro']);
        $foreign = Sector::factory()->create(['name' => 'Diretoria']);
        $this->agent->sectors()->attach($second);

        $screen = Livewire::actingAs($this->agent)->test(NewConversation::class)
            ->dispatch('start-conversation', contactId: $this->contact->id)
            ->assertSet('sectorId', null)
            ->assertSee('Vendas')
            ->assertSee('Financeiro')
            ->assertDontSee('Diretoria');

        $screen->set('sectorId', $foreign->id)->set('text', 'Olá')->call('submit')->assertSet('failure', 'Escolha um setor que receba tickets e que você atenda.');

        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_selects_de_canal_e_setor_tem_opcao_vazia_visivel_e_o_erro_aparece_uma_vez(): void
    {
        $second = Sector::factory()->create(['name' => 'Financeiro']);
        $this->agent->sectors()->attach($second);

        $screen = Livewire::actingAs($this->agent)->test(NewConversation::class)
            ->dispatch('start-conversation', contactId: $this->contact->id);

        $this->assertMatchesRegularExpression('/value=""[^>]*>Escolha o setor<\/option>/', $screen->html());

        $html = $screen->set('text', 'Olá')->call('submit')->assertHasErrors('sectorId')->html();

        $this->assertSame(1, substr_count($html, 'Escolha o setor.'));
    }

    public function test_raiz_da_janela_nao_ocupa_celula_da_grade_do_inbox(): void
    {
        // A janela vive dentro da grade do Inbox (e da de Contatos): a raiz precisa sair do fluxo, senão vira uma célula
        // a mais e empurra o painel de detalhes para baixo da fila.
        $html = Livewire::actingAs($this->agent)->test(NewConversation::class)->html();

        $this->assertMatchesRegularExpression('/^<div[^>]*class="absolute"/', trim($html));
    }

    public function test_busca_por_nome_lista_contatos(): void
    {
        Livewire::actingAs($this->agent)->test(NewConversation::class)
            ->dispatch('start-conversation')
            ->set('search', 'Maria')
            ->assertSee('Maria Souza');
    }

    public function test_inicia_pelo_contato_escolhido_e_vai_para_o_ticket(): void
    {
        $screen = Livewire::actingAs($this->agent)->test(NewConversation::class)
            ->dispatch('start-conversation', contactId: $this->contact->id)
            ->set('text', 'Bom dia, Maria!')
            ->call('submit');

        $ticket = Ticket::query()->firstOrFail();
        $screen->assertRedirect(route('inbox', ['ticket' => $ticket->id]));
        $this->assertSame('Bom dia, Maria!', Message::query()->firstOrFail()->body);
        Queue::assertPushed(SendTicketMessageJob::class);
    }

    public function test_telefone_novo_cadastra_o_contato_e_inicia(): void
    {
        Livewire::actingAs($this->agent)->test(NewConversation::class)
            ->dispatch('start-conversation')
            ->set('phone', '(16) 98888-7777')
            ->set('name', '')
            ->set('channelId', $this->channel->id)
            ->set('sectorId', $this->sector->id)
            ->set('text', 'Olá!')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertTrue(Contact::query()->where('wa_id', '5516988887777@c.us')->exists());
        $this->assertSame(1, Ticket::query()->count());
    }

    public function test_telefone_novo_sem_whatsapp_nao_cadastra_contato_nem_ticket(): void
    {
        $this->fakeCheck(false);
        $before = Contact::query()->count();

        Livewire::actingAs($this->agent)->test(NewConversation::class)
            ->dispatch('start-conversation')
            ->set('phone', '(16) 98888-7777')
            ->set('channelId', $this->channel->id)
            ->set('sectorId', $this->sector->id)
            ->set('text', 'Olá!')
            ->call('submit')
            ->assertSet('failure', 'Este número não tem WhatsApp.');

        $this->assertSame($before, Contact::query()->count());
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_telefone_invalido_mostra_erro_no_campo(): void
    {
        Livewire::actingAs($this->agent)->test(NewConversation::class)
            ->dispatch('start-conversation')
            ->set('phone', '123')
            ->set('channelId', $this->channel->id)
            ->set('sectorId', $this->sector->id)
            ->set('text', 'Olá!')
            ->call('submit')
            ->assertHasErrors('phone');
    }

    public function test_contato_com_ticket_aberto_visivel_vai_direto_ao_ticket(): void
    {
        $open = Ticket::factory()->create(['contact_id' => $this->contact->id, 'channel_id' => $this->channel->id, 'sector_id' => $this->sector->id, 'status' => TicketStatus::AGUARDANDO]);

        Livewire::actingAs($this->agent)->test(NewConversation::class)
            ->dispatch('start-conversation', contactId: $this->contact->id)
            ->assertRedirect(route('inbox', ['ticket' => $open->id]))
            ->assertSet('contactId', null)
            ->assertNotDispatched('modal-show');

        $this->assertStringContainsString('Maria Souza', (string) session('inbox-notice'));
    }

    public function test_o_aviso_do_desvio_nao_promete_texto_no_campo_quando_nada_foi_digitado(): void
    {
        Ticket::factory()->create(['contact_id' => $this->contact->id, 'channel_id' => $this->channel->id, 'sector_id' => $this->sector->id, 'status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $this->agent->id]);

        Livewire::actingAs($this->agent)->test(NewConversation::class)->dispatch('start-conversation', contactId: $this->contact->id);

        $this->assertStringContainsString('Já existe uma conversa aberta com Maria Souza', (string) session('inbox-notice'));
        $this->assertStringNotContainsString('campo de resposta', (string) session('inbox-notice'));
    }

    public function test_com_ticket_aberto_em_dois_canais_vai_ao_mais_recente(): void
    {
        $other = Channel::factory()->connected()->create(['waha_session' => 'outro']);
        Ticket::factory()->create(['contact_id' => $this->contact->id, 'channel_id' => $this->channel->id, 'sector_id' => $this->sector->id, 'status' => TicketStatus::AGUARDANDO, 'opened_at' => now()->subDays(2)]);
        $recent = Ticket::factory()->create(['contact_id' => $this->contact->id, 'channel_id' => $other->id, 'sector_id' => $this->sector->id, 'status' => TicketStatus::AGUARDANDO, 'opened_at' => now()->subHour()]);

        Livewire::actingAs($this->agent)->test(NewConversation::class)
            ->dispatch('start-conversation', contactId: $this->contact->id)
            ->assertRedirect(route('inbox', ['ticket' => $recent->id]));
    }

    public function test_ticket_aberto_de_outro_setor_abre_a_janela(): void
    {
        $foreign = Sector::factory()->create(['name' => 'Diretoria']);
        $boss = User::factory()->create(['role' => UserRole::ATENDENTE]);
        Ticket::factory()->create(['contact_id' => $this->contact->id, 'channel_id' => $this->channel->id, 'sector_id' => $foreign->id, 'status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $boss->id]);

        Livewire::actingAs($this->agent)->test(NewConversation::class)
            ->dispatch('start-conversation', contactId: $this->contact->id)
            ->assertNoRedirect()
            ->assertSet('contactId', $this->contact->id)
            ->assertDispatched('modal-show', name: 'new-conversation');
    }

    public function test_ticket_encerrado_nao_desvia_para_o_inbox(): void
    {
        Ticket::factory()->create(['contact_id' => $this->contact->id, 'channel_id' => $this->channel->id, 'sector_id' => $this->sector->id, 'status' => TicketStatus::ENCERRADO, 'closed_at' => now()->subHour()]);

        Livewire::actingAs($this->agent)->test(NewConversation::class)
            ->dispatch('start-conversation', contactId: $this->contact->id)
            ->assertNoRedirect()
            ->assertDispatched('modal-show', name: 'new-conversation');
    }

    public function test_ticket_aberto_de_outro_atendente_leva_o_aviso_e_nao_o_texto(): void
    {
        $colleague = User::factory()->create(['role' => UserRole::ATENDENTE, 'name' => 'Carlos Dias']);
        $colleague->sectors()->attach($this->sector);
        $open = Ticket::factory()->create(['contact_id' => $this->contact->id, 'channel_id' => $this->channel->id, 'sector_id' => $this->sector->id, 'status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $colleague->id]);

        // O ticket abriu depois de a janela abrir (corrida): o `submit` ainda trata o caso. Escolher o contato pela busca (`pick`) não passa pelo desvio do `open`.
        Livewire::actingAs($this->agent)->test(NewConversation::class)
            ->call('pick', $this->contact->id)
            ->set('text', 'Posso falar agora?')
            ->call('submit')
            ->assertRedirect(route('inbox', ['ticket' => $open->id]));

        $this->assertStringContainsString('Carlos Dias', (string) session('inbox-notice'));
        $this->assertNull(session('inbox-draft'));
        $this->assertSame(0, Message::query()->count());
    }

    public function test_ticket_aberto_do_proprio_atendente_leva_o_texto_ao_campo(): void
    {
        $open = Ticket::factory()->create(['contact_id' => $this->contact->id, 'channel_id' => $this->channel->id, 'sector_id' => $this->sector->id, 'status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $this->agent->id]);

        Livewire::actingAs($this->agent)->test(NewConversation::class)
            ->call('pick', $this->contact->id)
            ->set('text', 'Posso falar agora?')
            ->call('submit')
            ->assertRedirect(route('inbox', ['ticket' => $open->id]));

        $this->assertSame('Posso falar agora?', session('inbox-draft'));
        $this->assertSame(0, Message::query()->count());

        Livewire::actingAs($this->agent)->withQueryParams(['ticket' => $open->id])->test(Inbox::class)->assertSet('draft', 'Posso falar agora?');
    }

    public function test_cabecalho_da_fila_tem_o_botao_nova_conversa(): void
    {
        Livewire::actingAs($this->agent)->test(Inbox::class)
            ->assertSee('Nova conversa')
            ->assertSeeHtml("\$dispatch('start-conversation')");
    }
}
