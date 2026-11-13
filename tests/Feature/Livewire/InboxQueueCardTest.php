<?php

namespace Tests\Feature\Livewire;

use App\Enums\MessageDirection;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    private function part(string $html, string $testid, bool $tag = false): string
    {
        if ($tag) {
            $this->assertSame(1, preg_match('/<span[^>]*data-testid="'.$testid.'"[^>]*>/', $html, $m), "{$testid} ausente");

            return $m[0];
        }

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

    /** @return list<string> */
    private function names(int $count): array
    {
        return array_slice(['Alfa', 'Beta', 'Cama', 'Delta', 'Eta', 'Fox', 'Gama', 'Hotel'], 0, $count);
    }

    private function conversationTags(Ticket $ticket, int $count): void
    {
        $ticket->tags()->attach(Tag::factory()->count($count)->sequence(...array_map(fn (string $name): array => ['name' => $name], $this->names($count)))->create());
    }

    public function test_com_exatamente_seis_etiquetas_nao_aparece_o_mais_n(): void
    {
        $ticket = $this->ticket();
        $this->conversationTags($ticket, 6);

        $html = $this->html();

        $this->assertSame(6, substr_count($html, 'data-testid="card-chip-ticket"'));
        $this->assertStringNotContainsString('data-testid="card-tags-more"', $html);
    }

    public function test_com_sete_etiquetas_aparece_mais_1(): void
    {
        $ticket = $this->ticket();
        $this->conversationTags($ticket, 7);

        $html = $this->html();

        $this->assertSame(6, substr_count($html, 'data-testid="card-chip-ticket"'));
        $this->assertStringContainsString('+1', $this->part($html, 'card-tags-more'));
    }

    public function test_mostra_ate_seis_chips_com_o_do_contato_primeiro_e_mais_n(): void
    {
        $ticket = $this->ticket();
        $ticket->contact->tags()->attach(Tag::factory()->contato()->create(['name' => 'Revenda']));
        $this->conversationTags($ticket, 7);

        $html = $this->html();

        $this->assertStringContainsString('+2', $this->part($html, 'card-tags-more'));
        $this->assertSame(1, substr_count($html, 'data-testid="card-chip-contact"'));
        $this->assertSame(5, substr_count($html, 'data-testid="card-chip-ticket"'));
        $this->assertLessThan(strpos($html, 'data-testid="card-chip-ticket"'), strpos($html, 'data-testid="card-chip-contact"'), 'o chip do contato vem primeiro');
        $this->assertMatchesRegularExpression('/<span[^>]*data-testid="card-chip-contact"[^>]*>/', $html);
        $this->assertStringContainsString('bg-[var(--tag-bg)]', $this->part($html, 'card-chip-contact', true));
        $this->assertStringContainsString('bg-[var(--tag-line)]/15', $this->part($html, 'card-chip-ticket', true));
    }

    /** `opacity-70` no número da aba dava 3,4:1 sobre o fundo do seletor; o número usa a cor de texto, sem opacidade. */
    public function test_contador_das_abas_nao_usa_opacidade_no_texto(): void
    {
        $this->ticket(['status' => TicketStatus::AGUARDANDO]);

        $html = $this->html();

        $this->assertStringContainsString('data-testid="tab-aguardando"', $html);
        $this->assertStringNotContainsString('font-mono opacity-70', $html);
    }

    public function test_icone_do_canal_aparece_sobre_o_avatar(): void
    {
        $this->ticket();

        $this->assertStringContainsString('data-testid="card-channel-icon"', $this->html());
    }

    public function test_nao_lidas_contam_as_mensagens_do_cliente_e_somem_depois_do_visto(): void
    {
        $ticket = $this->ticket();
        Message::factory()->for($ticket)->count(2)->create(['direction' => MessageDirection::IN, 'sent_at' => now()->subMinutes(3)]);

        $this->assertStringContainsString('aria-hidden="true">2', $this->part($this->html(), 'card-unread'));

        $ticket->forceFill(['agent_seen_at' => now(), 'agent_seen_message_id' => $ticket->messages()->max('id')])->save();

        $this->assertStringNotContainsString('data-testid="card-unread"', $this->html());
    }

    public function test_a_lista_nao_faz_consulta_por_ticket_ao_mostrar_chips_e_nao_lidas(): void
    {
        $count = function (int $tickets): int {
            Ticket::query()->delete();
            foreach (range(1, $tickets) as $i) {
                $ticket = $this->ticket();
                $ticket->contact->tags()->attach(Tag::factory()->contato()->create());
                $ticket->tags()->attach(Tag::factory()->create());
            }
            $admin = User::factory()->create(['role' => UserRole::ADMIN]);
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($admin)->test(Inbox::class)->html();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(2), $count(12));
    }

    public function test_mais_n_diz_os_nomes_das_etiquetas_escondidas_a_quem_nao_ve_o_title(): void
    {
        $ticket = $this->ticket();
        $this->conversationTags($ticket, 8);

        $this->assertMatchesRegularExpression('/class="sr-only">[^<]*Gama, Hotel/', $this->html());
    }

    public function test_title_do_mais_n_fica_no_trecho_visual_e_nao_duplica_o_texto_do_leitor_de_tela(): void
    {
        $ticket = $this->ticket();
        $this->conversationTags($ticket, 8);

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$this->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $this->assertSame('', $xpath->query('//*[@data-testid="card-tags-more"]')->item(0)?->getAttribute('title'));
        $this->assertSame('Gama, Hotel', $xpath->query('//*[@data-testid="card-tags-more"]/*[@aria-hidden="true"]')->item(0)?->getAttribute('title'));
    }

    public function test_tempo_relativo_vem_da_espera_e_e_recalculado_no_navegador(): void
    {
        $this->travelTo(now()->startOfMinute());
        $this->ticket(['waiting_since' => now()->subMinutes(12)]);

        $html = $this->html();

        $this->assertSame('há 12 min.', trim($this->part($html, 'card-time')));
        $this->assertStringContainsString('x-data="relativeTime(', $html);
    }

    public function test_so_o_tempo_nao_cria_a_linha_de_meta(): void
    {
        $this->ticket(['waiting_since' => now()->subMinutes(5)]);

        $html = $this->html();

        $this->assertStringContainsString('data-testid="card-time"', $html);
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
