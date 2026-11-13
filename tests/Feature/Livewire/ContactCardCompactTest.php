<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Contacts\ContactCard;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ContactCardCompactTest extends TestCase
{
    use RefreshDatabase;

    private Contact $contact;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contact = Contact::factory()->create(['name' => 'Joaquim Silva', 'company' => 'Metalúrgica Norte', 'notes' => 'Prefere falar de manhã']);
        $this->user = User::factory()->create(['role' => UserRole::ADMIN]);
    }

    /** @param  array<string, mixed>  $props */
    private function card(array $props = []): Testable
    {
        return Livewire::actingAs($this->user)->test(ContactCard::class, ['contactId' => $this->contact->id, ...$props]);
    }

    /** @return list<Ticket> Do mais antigo ao mais novo, encerrados. */
    private function past(int $count): array
    {
        $channel = Channel::query()->first() ?? Channel::factory()->create();

        return array_map(fn (int $i) => Ticket::factory()->create([
            'channel_id' => $channel->id,
            'contact_id' => $this->contact->id,
            'status' => TicketStatus::ENCERRADO,
            'opened_at' => now()->subDays(30 - $i),
            'closed_at' => now()->subDays(30 - $i),
        ]), range(1, $count));
    }

    private function detailsTag(string $html, string $testid): string
    {
        $this->assertSame(1, preg_match('/<details[^>]*data-testid="'.$testid.'"[^>]*>/', $html, $m), "bloco {$testid} ausente");

        return $m[0];
    }

    public function test_mostra_so_os_5_ultimos_e_o_link_ao_historico(): void
    {
        $tickets = $this->past(7);

        $card = $this->card(['compact' => true])
            ->assertSee('Anteriores (7)')
            ->assertSee('Ver todos no Histórico')
            ->assertSeeHtml('contactId='.$this->contact->id);

        foreach (array_slice($tickets, 2) as $shown) {
            $card->assertSee($shown->protocol);
        }
        $card->assertDontSee($tickets[0]->protocol)->assertDontSee($tickets[1]->protocol);
    }

    public function test_sem_link_com_5_ou_menos(): void
    {
        $this->past(5);

        $this->card(['compact' => true])->assertSee('Anteriores (5)')->assertDontSee('Ver todos no Histórico');
    }

    public function test_sem_anteriores_o_bloco_vem_fechado(): void
    {
        $tag = $this->detailsTag($this->card(['compact' => true])->html(), 'details-history');

        $this->assertDoesNotMatchRegularExpression('/\sopen[\s>]/', $tag);
    }

    /** `wire:ignore.self` congela o `open` do primeiro render: trocar a chave recria o bloco quando o contato passa a ter anteriores. */
    public function test_bloco_de_anteriores_muda_de_chave_quando_passa_a_ter_anteriores(): void
    {
        $card = $this->card(['compact' => true]);
        $this->assertStringContainsString('wire:key="history-block-none"', $card->html());

        $this->past(1);

        $html = $card->call('$refresh')->html();
        $this->assertStringContainsString('wire:key="history-block-has"', $html);
        $this->assertMatchesRegularExpression('/\sopen[\s>]/', $this->detailsTag($html, 'details-history'));
    }

    public function test_ficha_completa_tambem_limita_a_5_sem_blocos(): void
    {
        $tickets = $this->past(7);

        $this->card()
            ->assertSee($tickets[6]->protocol)
            ->assertDontSee($tickets[0]->protocol)
            ->assertSee('Ver todos no Histórico')
            ->assertDontSeeHtml('data-testid="details-contact"');
    }

    public function test_inbox_mostra_o_resumo_e_esconde_o_formulario(): void
    {
        $this->card(['compact' => true])
            ->assertSee('Joaquim Silva')
            ->assertSee('Metalúrgica Norte')
            ->assertSee('Prefere falar de manhã')
            ->assertSee('Editar contato')
            ->assertDontSee('Salvar contato');
    }

    public function test_editar_abre_o_formulario_e_cancelar_volta_ao_resumo(): void
    {
        $this->card(['compact' => true])
            ->call('toggleEdit')
            ->assertSee('Salvar contato')
            ->assertSee('Cancelar')
            ->call('toggleEdit')
            ->assertDontSee('Salvar contato');
    }

    public function test_salvar_grava_e_volta_ao_resumo(): void
    {
        $this->card(['compact' => true])
            ->call('toggleEdit')
            ->set('form.name', 'Joaquim S. Filho')
            ->call('save')
            ->assertSet('editing', false)
            ->assertSee('Joaquim S. Filho')
            ->assertDontSee('Salvar contato');

        $this->assertSame('Joaquim S. Filho', $this->contact->fresh()?->name);
    }

    public function test_aviso_do_google_so_aparece_com_erro(): void
    {
        $this->card(['compact' => true])->assertDontSee('Reenviar');

        $this->contact->forceFill(['google_error' => 'Quota excedida'])->save();

        $this->card(['compact' => true])->assertSee('Reenviar');
    }

    public function test_em_contatos_o_formulario_continua_aberto(): void
    {
        $this->card()->assertSee('Salvar contato')->assertDontSee('Editar contato');
    }

    public function test_somente_leitura_nao_edita_nem_salva(): void
    {
        $this->card(['compact' => true, 'readonly' => true])
            ->assertSee('Joaquim Silva')
            ->assertDontSee('Editar contato')
            ->call('toggleEdit')->assertForbidden();

        $this->card(['compact' => true, 'readonly' => true])->call('save')->assertForbidden();
        $this->card(['compact' => true, 'readonly' => true])->call('resync')->assertForbidden();
    }

    public function test_lista_de_anteriores_nao_repete_o_ticket_atual_e_so_linka_o_que_o_usuario_ve(): void
    {
        $channel = Channel::factory()->create();
        $mine = Sector::factory()->create();
        $other = Sector::factory()->create();
        $make = fn (Sector $sector, TicketStatus $status): Ticket => Ticket::factory()->create([
            'channel_id' => $channel->id, 'contact_id' => $this->contact->id, 'sector_id' => $sector->id,
            'status' => $status, 'closed_at' => $status === TicketStatus::ENCERRADO ? now()->subDay() : null,
        ]);
        $visible = $make($mine, TicketStatus::ENCERRADO);
        $hidden = $make($other, TicketStatus::ENCERRADO);
        $current = $make($mine, TicketStatus::EM_ATENDIMENTO);
        $agent = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $agent->sectors()->attach($mine);

        $html = Livewire::actingAs($agent)->test(ContactCard::class, ['contactId' => $this->contact->id, 'compact' => true, 'currentTicketId' => $current->id])->html();

        $this->assertStringNotContainsString((string) $current->protocol, $html);
        $this->assertStringContainsString('ticketId='.$visible->id, $html);
        $this->assertStringContainsString((string) $hidden->protocol, $html);
        $this->assertStringNotContainsString('ticketId='.$hidden->id, $html);
    }

    public function test_ticket_aberto_na_ficha_do_historico_leva_para_a_fila(): void
    {
        $channel = Channel::factory()->create();
        $open = Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => $this->contact->id, 'status' => TicketStatus::EM_ATENDIMENTO]);
        $current = Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => $this->contact->id, 'status' => TicketStatus::ENCERRADO, 'closed_at' => now()]);

        $html = $this->card(['compact' => true, 'readonly' => true, 'currentTicketId' => $current->id])->html();

        $this->assertStringContainsString(route('inbox', ['ticket' => $open->id]), $html);
        $this->assertStringNotContainsString('$parent.select', $html);
    }

    public function test_na_fila_o_ticket_aberto_continua_abrindo_sem_recarregar(): void
    {
        $channel = $this->channelWith('a', '5516999990001');
        Ticket::factory()->create(['channel_id' => $this->channelWith('b', '5516988887777')->id, 'contact_id' => $this->contact->id, 'status' => TicketStatus::EM_ATENDIMENTO]);
        $current = Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => $this->contact->id, 'status' => TicketStatus::AGUARDANDO]);

        $this->assertStringContainsString('$parent.select', $this->card(['compact' => true, 'currentTicketId' => $current->id])->html());
    }

    public function test_em_contatos_o_protocolo_leva_ao_ticket(): void
    {
        $channel = Channel::factory()->create();
        $closed = Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => $this->contact->id, 'status' => TicketStatus::ENCERRADO, 'closed_at' => now()->subDay(), 'protocol' => '2026-000951']);
        $open = Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => $this->contact->id, 'status' => TicketStatus::EM_ATENDIMENTO, 'protocol' => '2026-000952']);

        $html = $this->card()->html();

        $this->assertMatchesRegularExpression('#<a href="[^"]*/historico\?ticketId='.$closed->id.'"[^>]*>\s*2026-000951\s*</a>#', $html);
        $this->assertMatchesRegularExpression('#<a href="[^"]*/inbox\?ticket='.$open->id.'"[^>]*>\s*2026-000952\s*</a>#', $html);
    }

    public function test_em_contatos_ticket_que_o_usuario_nao_ve_fica_sem_link(): void
    {
        $mine = Sector::factory()->create();
        $hidden = Ticket::factory()->create([
            'channel_id' => Channel::factory()->create()->id, 'contact_id' => $this->contact->id, 'sector_id' => Sector::factory()->create()->id,
            'status' => TicketStatus::ENCERRADO, 'closed_at' => now()->subDay(), 'protocol' => '2026-000953',
        ]);
        $agent = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $agent->sectors()->attach($mine);

        $html = Livewire::actingAs($agent)->test(ContactCard::class, ['contactId' => $this->contact->id])->html();

        $this->assertStringContainsString('2026-000953', $html);
        $this->assertStringNotContainsString('ticketId='.$hidden->id, $html);
    }

    private function channelWith(string $session, string $phone): Channel
    {
        return Channel::factory()->connected()->create(['waha_session' => $session, 'phone' => $phone]);
    }

    private function ticketOn(Channel $channel, TicketStatus $status, ?Sector $sector = null, ?User $assignee = null): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => $channel->id, 'contact_id' => $this->contact->id, 'sector_id' => $sector?->id,
            'assigned_user_id' => $assignee?->id, 'status' => $status,
            'closed_at' => $status === TicketStatus::ENCERRADO ? now()->subDay() : null,
        ]);
    }

    public function test_ticket_de_outro_numero_mostra_o_numero_e_o_do_mesmo_numero_nao_repete(): void
    {
        $a = $this->channelWith('a', '5516999990001');
        $b = $this->channelWith('b', '5516988887777');
        $current = $this->ticketOn($a, TicketStatus::EM_ATENDIMENTO);
        $this->ticketOn($b, TicketStatus::ENCERRADO);
        $this->ticketOn($a, TicketStatus::ENCERRADO);

        $html = $this->card(['compact' => true, 'currentTicketId' => $current->id])->assertSee('número (16) 98888-7777')->html();

        $this->assertStringNotContainsString('(16) 99999-0001', $html);
    }

    public function test_ticket_aberto_em_outro_numero_ganha_destaque_e_link_para_o_inbox(): void
    {
        $current = $this->ticketOn($this->channelWith('a', '5516999990001'), TicketStatus::EM_ATENDIMENTO);
        $open = $this->ticketOn($this->channelWith('b', '5516988887777'), TicketStatus::AGUARDANDO);

        $this->card(['compact' => true, 'currentTicketId' => $current->id])
            ->assertSee('também tem ticket aberto no número (16) 98888-7777')
            ->assertSeeHtml('$parent.select('.$open->id.')');
    }

    public function test_ticket_aberto_no_mesmo_numero_tambem_linka_sem_destaque(): void
    {
        $channel = $this->channelWith('a', '5516999990001');
        $current = $this->ticketOn($channel, TicketStatus::ENCERRADO);
        $open = $this->ticketOn($channel, TicketStatus::AGUARDANDO);

        $this->card(['compact' => true, 'currentTicketId' => $current->id])
            ->assertSeeHtml('$parent.select('.$open->id.')')
            ->assertDontSee('também tem ticket aberto');
    }

    public function test_somente_leitura_mostra_o_aberto_sem_link(): void
    {
        $current = $this->ticketOn($this->channelWith('a', '5516999990001'), TicketStatus::ENCERRADO);
        $open = $this->ticketOn($this->channelWith('b', '5516988887777'), TicketStatus::AGUARDANDO);

        $this->card(['compact' => true, 'readonly' => true, 'currentTicketId' => $current->id])
            ->assertSee((string) $open->protocol)
            ->assertDontSeeHtml('$parent.select(');
    }

    public function test_sem_acesso_nao_mostra_setor_nem_link(): void
    {
        $current = $this->ticketOn($this->channelWith('a', '5516999990001'), TicketStatus::AGUARDANDO);
        $hidden = $this->ticketOn($this->channelWith('b', '5516988887777'), TicketStatus::EM_ATENDIMENTO, Sector::factory()->create(['name' => 'Setor Reservado']), User::factory()->create());

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))
            ->test(ContactCard::class, ['contactId' => $this->contact->id, 'compact' => true, 'currentTicketId' => $current->id])
            ->assertSee((string) $hidden->protocol)
            ->assertDontSee('Setor Reservado')
            ->assertDontSeeHtml('$parent.select(');
    }

    public function test_refresh_traz_ticket_novo_e_nao_mexe_na_edicao(): void
    {
        $channel = $this->channelWith('a', '5516999990001');
        $current = $this->ticketOn($channel, TicketStatus::EM_ATENDIMENTO);
        $screen = $this->card(['compact' => true, 'currentTicketId' => $current->id]);

        $new = $this->ticketOn($this->channelWith('b', '5516988887777'), TicketStatus::AGUARDANDO);
        $screen->call('refresh')->assertSee((string) $new->protocol);

        $this->card(['compact' => true, 'currentTicketId' => $current->id])
            ->call('toggleEdit')->set('form.name', 'Digitado')->call('refresh')
            ->assertSet('editing', true)->assertSet('form.name', 'Digitado');
    }

    private function dom(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($dom);
    }

    /** Rodapé fixo no padrão Flux: `button.group` com as duas ações, separador do Flux acima, grudado no fim do painel. */
    public function test_acoes_do_contato_ficam_num_grupo_de_botoes_no_rodape(): void
    {
        $this->contact->update(['wa_id' => '5511999990000@c.us']);
        $xpath = $this->dom($this->card(['compact' => true])->html());

        $footer = $xpath->query('//*[@data-testid="contact-actions"]')->item(0);

        $this->assertNotNull($footer);
        $this->assertStringContainsString('sticky', $footer->getAttribute('class'));
        // `-bottom-4` (= o padding do painel): sem isso o rodapé gruda 16px acima do fim e deixa texto aparecer por baixo.
        $this->assertStringContainsString('-bottom-4', $footer->getAttribute('class'));
        $this->assertSame(1, $xpath->query('.//*[@data-flux-separator]', $footer)->length);
        $group = $xpath->query('.//*[@data-flux-button-group]', $footer)->item(0);
        $this->assertNotNull($group);
        $labels = [];
        foreach ($xpath->query('.//button', $group) as $button) {
            $labels[] = trim($button->textContent);
            $this->assertStringNotContainsString('w-full', $button->getAttribute('class'));
            $this->assertStringContainsString('h-6', $button->getAttribute('class'), 'botões xs cabem os dois lado a lado no painel de 280px');
        }
        $this->assertSame(['Conversar', 'Editar contato'], $labels);
    }

    /** "Conversar" abre Nova conversa com o contato: em ticket ativo a conversa já está aberta (inútil); em encerrado serve para recomeçar. */
    public function test_conversar_so_aparece_em_ticket_encerrado_ou_sem_ticket(): void
    {
        $this->contact->update(['wa_id' => '5511999990000@c.us']);
        $channel = Channel::factory()->create();
        $active = Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => $this->contact->id, 'status' => TicketStatus::EM_ATENDIMENTO]);
        $closed = Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => $this->contact->id, 'status' => TicketStatus::ENCERRADO, 'closed_at' => now()]);

        $this->assertStringNotContainsString('Conversar', $this->card(['compact' => true, 'currentTicketId' => $active->id])->html());
        $this->assertStringContainsString('Editar contato', $this->card(['compact' => true, 'currentTicketId' => $active->id])->html());
        $this->assertStringContainsString('Conversar', $this->card(['compact' => true, 'currentTicketId' => $closed->id])->html());
        $this->assertStringContainsString('Conversar', $this->card(['compact' => true])->html());
    }

    public function test_somente_leitura_so_tem_o_conversar_no_rodape(): void
    {
        $this->contact->update(['wa_id' => '5511999990000@c.us']);
        $html = $this->card(['compact' => true, 'readonly' => true])->html();

        $this->assertSame(1, $this->dom($html)->query('//*[@data-testid="contact-actions"]')->length);
        $this->assertStringContainsString('Conversar', $html);
        $this->assertStringNotContainsString('Editar contato', $html);
    }

    public function test_somente_leitura_com_ticket_ativo_nao_tem_rodape(): void
    {
        $active = Ticket::factory()->create(['channel_id' => Channel::factory()->create()->id, 'contact_id' => $this->contact->id, 'status' => TicketStatus::EM_ATENDIMENTO]);

        $this->assertSame(0, $this->dom($this->card(['compact' => true, 'readonly' => true, 'currentTicketId' => $active->id])->html())->query('//*[@data-testid="contact-actions"]')->length);
    }

    /** `contents`: o cartão não gera caixa, e o rodapé `sticky` passa a grudar no fim do `aside` de rolagem (só no modo compacto). */
    public function test_raiz_do_cartao_compacto_nao_gera_caixa_e_o_completo_sim(): void
    {
        $compact = $this->dom($this->card(['compact' => true])->html());
        $this->assertStringContainsString('contents', $compact->query('//div[@*[name()="wire:id"]]')->item(0)?->getAttribute('class') ?? '');
        $this->assertGreaterThan(0, $compact->query('//div[contains(@class, "contents") and contains(@class, "text-sm")]')->length);

        $full = $this->dom($this->card()->html());
        $this->assertStringNotContainsString('contents', $full->query('//div[@*[name()="wire:id"]]')->item(0)?->getAttribute('class') ?? '');
    }
}
