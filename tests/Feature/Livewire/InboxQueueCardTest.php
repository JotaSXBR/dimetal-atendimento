<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InboxQueueCardTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::factory()->create();
    }

    /** @param  array<string, mixed>  $override */
    private function ticket(array $override = []): Ticket
    {
        return Ticket::factory()->create(array_replace([
            'channel_id' => $this->channel->id,
            'contact_id' => Contact::factory()->create(['name' => 'Joaquim'])->id,
            'status' => TicketStatus::AGUARDANDO,
            'waiting_since' => null,
            'sla_warn_at' => null,
            'sla_due_at' => null,
        ], $override));
    }

    private function html(): string
    {
        return Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Inbox::class)->html();
    }

    /** O trecho de um elemento do card com esse data-testid, até o fechamento da tag. */
    private function part(string $html, string $testid): string
    {
        $this->assertSame(1, preg_match('/data-testid="'.$testid.'"[^>]*>(.*?)<\/span>/s', $html, $m), "{$testid} ausente");

        return $m[1];
    }

    public function test_lista_da_fila_e_relative_para_o_sr_only_nao_escapar_do_corte(): void
    {
        $this->ticket();

        $this->assertStringContainsString('relative min-h-0 flex-1 overflow-y-auto', $this->html());
    }

    public function test_card_mostra_atendente_pelo_primeiro_nome(): void
    {
        $this->ticket(['status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => User::factory()->create(['name' => 'Marina Souza'])->id]);

        $assignee = $this->part($this->html(), 'card-assignee-name');

        $this->assertStringContainsString('Marina', $assignee);
        $this->assertStringNotContainsString('Souza', $assignee);
    }

    public function test_sem_atendente_etiqueta_nem_espera_nao_tem_linha_3(): void
    {
        $this->ticket();

        $this->assertStringNotContainsString('data-testid="card-meta"', $this->html());
    }

    public function test_setor_some_com_um_so_setor_e_aparece_com_dois(): void
    {
        $vendas = Sector::factory()->create(['name' => 'Vendas']);
        $this->ticket(['sector_id' => $vendas->id]);

        $this->assertStringNotContainsString('data-testid="card-sector"', $this->html());

        Sector::factory()->create(['name' => 'Suporte']);

        $this->assertStringContainsString('Vendas', $this->part($this->html(), 'card-sector'));
    }

    public function test_mostra_duas_etiquetas_e_mais_n(): void
    {
        $ticket = $this->ticket();
        $ticket->tags()->attach(Tag::factory()->count(4)->sequence(['name' => 'Alfa'], ['name' => 'Beta'], ['name' => 'Gama'], ['name' => 'Delta'])->create());

        $html = $this->html();

        $this->assertStringContainsString('+2', $this->part($html, 'card-tags-more'));
        $this->assertSame(2, substr_count($html, 'data-testid="card-tag"'));
    }

    public function test_mais_n_diz_os_nomes_das_etiquetas_escondidas_a_quem_nao_ve_o_title(): void
    {
        $ticket = $this->ticket();
        $ticket->tags()->attach(Tag::factory()->count(4)->sequence(['name' => 'Alfa'], ['name' => 'Beta'], ['name' => 'Gama'], ['name' => 'Delta'])->create());

        $this->assertMatchesRegularExpression('/class="sr-only">[^<]*Gama, Delta/', $this->html());
    }

    public function test_title_do_mais_n_fica_no_trecho_visual_e_nao_duplica_o_texto_do_leitor_de_tela(): void
    {
        $ticket = $this->ticket();
        $ticket->tags()->attach(Tag::factory()->count(4)->sequence(['name' => 'Alfa'], ['name' => 'Beta'], ['name' => 'Gama'], ['name' => 'Delta'])->create());

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$this->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $this->assertSame('', $xpath->query('//*[@data-testid="card-tags-more"]')->item(0)?->getAttribute('title'));
        $this->assertSame('Gama, Delta', $xpath->query('//*[@data-testid="card-tags-more"]/*[@aria-hidden="true"]')->item(0)?->getAttribute('title'));
    }

    public function test_espera_aparece_com_waiting_since(): void
    {
        $this->travelTo(now()->startOfMinute());
        $this->ticket(['waiting_since' => now()->subMinutes(12)]);

        $this->assertSame('há 12 min', trim($this->part($this->html(), 'card-wait')));
    }

    public function test_so_a_espera_fica_na_linha_da_previa_sem_criar_linha_3(): void
    {
        $this->ticket(['waiting_since' => now()->subMinutes(5)]);

        $html = $this->html();

        $this->assertStringContainsString('data-testid="card-wait"', $html);
        $this->assertStringNotContainsString('data-testid="card-meta"', $html);
    }

    public function test_sla_estourado_vira_ponto_com_texto_para_leitor_de_tela(): void
    {
        $this->ticket(['waiting_since' => now()->subHour(), 'sla_warn_at' => now()->subMinutes(30), 'sla_due_at' => now()->subMinute()]);

        $html = $this->html();
        $dot = $this->part($html, 'card-sla');

        $this->assertStringContainsString('SLA estourado', $dot);
        $this->assertStringContainsString('bg-danger', $html);
        $this->assertStringNotContainsString('Responder até', $html);
    }
}
