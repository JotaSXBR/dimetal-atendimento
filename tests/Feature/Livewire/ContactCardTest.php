<?php

namespace Tests\Feature\Livewire;

use App\Enums\CloseOutcome;
use App\Enums\GoogleConnectionStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Jobs\SyncContactToGoogleJob;
use App\Livewire\Contacts\ContactCard;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\GoogleConnection;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ContactCardTest extends TestCase
{
    use RefreshDatabase;

    private Contact $contact;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contact = Contact::factory()->create(['wa_id' => '5516997524950@c.us', 'name' => 'Push Name']);
        $this->user = User::factory()->create(['role' => UserRole::ATENDENTE]);
    }

    private function card(): Testable
    {
        return Livewire::actingAs($this->user)->test(ContactCard::class, ['contactId' => $this->contact->id]);
    }

    public function test_edita_e_salva_o_perfil_marcando_a_edicao(): void
    {
        $this->card()
            ->set('form.name', 'Maria Silva')
            ->set('form.company', 'Metalúrgica Silva')
            ->set('form.email', 'maria@silva.com.br')
            ->set('form.birthday', '1990-02-28')
            ->set('form.urls', ['https://silva.com.br'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('contact-saved');

        $fresh = $this->contact->fresh();
        $this->assertSame('Maria Silva', $fresh?->name);
        $this->assertSame('Metalúrgica Silva', $fresh?->company);
        $this->assertSame('maria@silva.com.br', $fresh?->email);
        $this->assertSame('1990-02-28', $fresh?->birthday?->toDateString());
        $this->assertSame(['https://silva.com.br'], $fresh?->urls);
        $this->assertNotNull($fresh?->edited_at);
    }

    public function test_mostra_os_dados_atuais_na_abertura(): void
    {
        $this->contact->update(['company' => 'Acme']);

        $this->card()->assertSet('form.name', 'Push Name')->assertSet('form.company', 'Acme');
    }

    public function test_mostra_erro_de_validacao_e_nao_grava(): void
    {
        $this->card()->set('form.email', 'nao-e-email')->call('save')->assertHasErrors(['form.email']);

        $this->assertNull($this->contact->fresh()?->edited_at);
    }

    public function test_telefone_aparece_formatado_e_nao_e_editavel(): void
    {
        $this->card()->assertSee('(16) 99752-4950')->assertDontSee('5516997524950@c.us')->assertDontSeeHtml('wire:model="form.waId"');
    }

    public function test_contactid_nao_pode_ser_trocado_pelo_navegador(): void
    {
        $other = Contact::factory()->create();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->card()->set('contactId', $other->id);
    }

    public function test_visitante_sem_login_nao_acessa_o_inbox(): void
    {
        $this->get('/inbox')->assertRedirect('/login');
    }

    public function test_adiciona_e_remove_endereco_de_site_ate_o_limite(): void
    {
        $card = $this->card();

        for ($i = 0; $i < 7; $i++) {
            $card->call('addUrl');
        }

        $card->assertCount('form.urls', 5)->call('removeUrl', 0)->assertCount('form.urls', 4);
    }

    public function test_o_inbox_mostra_a_ficha_do_contato_do_ticket(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $ticket = Ticket::factory()->for($this->contact)->for(Channel::factory()->connected()->create())->create();

        Livewire::actingAs($admin)->test(Inbox::class)->set('ticketId', $ticket->id)->assertSeeLivewire('contacts.contact-card');
    }

    private function connectGoogle(): void
    {
        GoogleConnection::query()->create([
            'account_email' => 'dimetal@gmail.com', 'access_token' => 'tok', 'refresh_token' => 'ref',
            'expires_at' => now()->addHour(), 'status' => GoogleConnectionStatus::CONNECTED, 'connected_at' => now(),
        ]);
    }

    public function test_ficha_diz_que_o_google_nao_esta_conectado(): void
    {
        $this->contact->update(['edited_at' => now()]);

        $this->card()->assertSee('Google não conectado');
    }

    public function test_contato_nunca_editado_so_vai_depois_da_primeira_edicao(): void
    {
        $this->connectGoogle();

        $this->card()->assertSee('depois da primeira edição');
    }

    public function test_ficha_mostra_pendente_sincronizado_e_erro(): void
    {
        $this->connectGoogle();
        $this->contact->update(['edited_at' => now()]);
        $this->card()->assertSee('Pendente');

        $this->contact->update(['google_synced_at' => now(), 'updated_at' => now(), 'google_resource_name' => 'people/c1']);
        $this->card()->assertSee('Sincronizado em')->assertDontSee('Pendente');

        $this->contact->update(['google_error' => 'rate_limited']);
        $this->card()->assertSee('Erro:')->assertSee('Reenviar');
    }

    public function test_ficha_explica_quando_nao_ha_telefone_nem_email_para_o_google(): void
    {
        $this->connectGoogle();
        $lidOnly = Contact::factory()->edited()->create(['wa_id' => null, 'lid' => '1@lid']);

        Livewire::actingAs($this->user)->test(ContactCard::class, ['contactId' => $lidOnly->id])
            ->assertSee('Sem telefone nem e-mail para enviar ao Google');
    }

    public function test_reenviar_limpa_o_erro_e_enfileira(): void
    {
        Queue::fake();
        $this->connectGoogle();
        $this->contact->update(['edited_at' => now(), 'google_error' => 'rate_limited', 'google_attempted_at' => now()]);

        $this->card()->call('resync');

        $this->assertNull($this->contact->fresh()?->google_error);
        Queue::assertPushedOn('google', SyncContactToGoogleJob::class, fn (SyncContactToGoogleJob $job): bool => $job->contactId === $this->contact->id);
    }

    public function test_reenviar_sem_conexao_nao_mexe_no_contato(): void
    {
        Queue::fake();
        $this->contact->update(['edited_at' => now(), 'google_error' => 'rate_limited']);

        $this->card()->call('resync');

        $this->assertSame('rate_limited', $this->contact->fresh()?->google_error);
        Queue::assertNothingPushed();
    }

    public function test_a_ficha_lista_os_10_atendimentos_mais_recentes_com_motivo_e_resumo(): void
    {
        $channel = Channel::factory()->connected()->create();
        foreach (range(1, 11) as $n) {
            Ticket::factory()->for($this->contact)->for($channel)->create([
                'status' => TicketStatus::ENCERRADO,
                'opened_at' => now()->subDays(20 - $n),
                'protocol' => sprintf('2026-%06d', $n),
                'close_outcome' => CloseOutcome::RESOLVIDO,
                'close_summary' => 'Resumo do ticket '.$n,
            ]);
        }

        $this->card()
            ->assertSee('Atendimentos')
            ->assertSee('2026-000011')->assertSee('Resumo do ticket 11')->assertSee('Resolvido')
            ->assertSee('2026-000002')
            ->assertDontSee('2026-000001');
    }

    public function test_ticket_aberto_aparece_sem_resumo_e_contato_sem_ticket_avisa(): void
    {
        $this->card()->assertSee('Nenhum atendimento ainda');

        Ticket::factory()->for($this->contact)->for(Channel::factory()->connected()->create())->create(['status' => TicketStatus::EM_ATENDIMENTO, 'protocol' => '2026-000500']);

        $this->card()->assertSee('2026-000500')->assertSee('Em atendimento')->assertDontSee('Nenhum atendimento ainda');
    }

    public function test_salvar_na_ficha_enfileira_o_envio_ao_google(): void
    {
        Queue::fake();
        $this->connectGoogle();

        $this->card()->set('form.name', 'Maria')->call('save');

        Queue::assertPushed(SyncContactToGoogleJob::class);
    }
}
