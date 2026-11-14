<?php

namespace Tests\Feature\Inbox;

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
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** A busca da Fila: nome, telefone e protocolo, sobre o que o usuário já vê (mesma regra do Histórico). */
class QueueSearchTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(string $name, string $waId, string $protocol, TicketStatus $status = TicketStatus::AGUARDANDO, ?Sector $sector = null): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => Channel::query()->firstOrCreate(['waha_session' => 'main'], ['name' => 'WhatsApp'])->id,
            'contact_id' => Contact::factory()->create(['name' => $name, 'wa_id' => $waId])->id,
            'status' => $status,
            'protocol' => $protocol,
            'sector_id' => $sector?->id,
            'closed_at' => $status === TicketStatus::ENCERRADO ? now()->subHour() : null,
        ]);
    }

    private function page(?User $as = null): Testable
    {
        return Livewire::actingAs($as ?? User::factory()->create(['role' => UserRole::ADMIN]))->test(Inbox::class);
    }

    private function pair(): void
    {
        $this->ticket('Maria Souza', '5516997524950@c.us', '2026-000029');
        $this->ticket('João Lima', '5511988887777@c.us', '2026-000030');
    }

    public function test_a_fila_tem_a_lupa_e_o_campo_e_abre_o_campo_se_ja_ha_busca(): void
    {
        $this->page()->assertSeeHtml('aria-label="Buscar na fila"')->assertSeeHtml('data-testid="queue-search-field"')->assertSeeHtml('data-open="0"');
        $this->page()->set('search', 'maria')->assertSeeHtml('data-open="1"');
    }

    public function test_acha_pelo_nome_parcial_sem_diferenciar_maiuscula(): void
    {
        $this->pair();

        $this->page()->set('search', 'mari')->assertSee('Maria Souza')->assertDontSee('João Lima');
    }

    public function test_telefone_com_mais_55_espacos_e_traco_acha_o_contato_certo(): void
    {
        $this->pair();

        foreach (['+55 16 99752-4950', '(16) 99752-4950', '99752-4950', '99752'] as $typed) {
            $this->page()->set('search', $typed)->assertSee('Maria Souza')->assertDontSee('João Lima');
        }
    }

    public function test_protocolo_parcial_acha_o_ticket(): void
    {
        $this->pair();

        $this->page()->set('search', '000029')->assertSee('Maria Souza')->assertDontSee('João Lima');
    }

    public function test_percentual_e_underscore_valem_como_texto_e_nao_como_curinga(): void
    {
        $this->ticket('Maria Souza', '5516997524950@c.us', '2026-000029');
        $this->ticket('Jo_ao 100%', '5511988887777@c.us', '2026-000030');

        $this->page()->set('search', '%')->assertSee('Jo_ao 100%')->assertDontSee('Maria Souza');
        $this->page()->set('search', '_')->assertSee('Jo_ao 100%')->assertDontSee('Maria Souza');
    }

    public function test_sem_resultado_diz_o_que_nao_achou_em_vez_de_fila_vazia(): void
    {
        $this->pair();

        $this->page()->set('search', 'zzz')->assertSee('Nada encontrado para «zzz».')->assertDontSee('Nenhum ticket por aqui.');
        $this->page()->set('tab', 'encerrados')->set('search', 'zzz')->assertSee('Nada encontrado para «zzz».')->assertDontSee('Nenhum ticket encerrado');
        $this->page()->assertSee('Maria Souza')->assertDontSee('Nada encontrado');
    }

    public function test_o_termo_digitado_aparece_escapado_na_mensagem_de_nada_encontrado(): void
    {
        $this->page()->set('search', '<b>x</b>')->assertSee('Nada encontrado')->assertDontSeeHtml('<b>x</b>');
    }

    public function test_busca_so_com_espacos_equivale_a_vazia(): void
    {
        $this->pair();

        $this->page()->set('search', '   ')->assertSee('Maria Souza')->assertSee('João Lima');
    }

    public function test_ticket_de_setor_que_o_usuario_nao_ve_nao_aparece_nem_digitando_o_nome_exato(): void
    {
        $vendas = Sector::factory()->create(['name' => 'Vendas']);
        $financeiro = Sector::factory()->create(['name' => 'Financeiro']);
        $attendant = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $attendant->sectors()->attach($vendas);

        $this->ticket('Maria Souza', '5516997524950@c.us', '2026-000029', sector: $vendas);
        $hidden = $this->ticket('João Lima', '5511988887777@c.us', '2026-000030', sector: $financeiro);

        // A tela repete o termo digitado ("Nada encontrado para «João Lima»"), mas nenhum card nem protocolo do ticket que o usuário não vê.
        $this->page($attendant)->set('search', 'João Lima')->assertSee('Nada encontrado')->assertDontSee('2026-000030')->assertDontSeeHtml('wire:key="ticket-'.$hidden->id.'"');
        $this->page($attendant)->set('search', 'Maria')->assertSee('Maria Souza');
    }

    public function test_na_visao_encerrados_a_busca_tambem_vale(): void
    {
        $this->ticket('Maria Souza', '5516997524950@c.us', '2026-000029', TicketStatus::ENCERRADO);
        $this->ticket('João Lima', '5511988887777@c.us', '2026-000030', TicketStatus::ENCERRADO);

        $this->page()->set('tab', 'encerrados')->assertSee('Maria Souza')->assertSee('João Lima')
            ->set('search', 'maria')->assertSee('Maria Souza')->assertDontSee('João Lima');
    }

    public function test_combina_com_o_filtro_de_etiqueta(): void
    {
        $tag = Tag::factory()->create();
        $maria = $this->ticket('Maria Souza', '5516997524950@c.us', '2026-000029');
        $mariana = $this->ticket('Mariana Dias', '5511977776666@c.us', '2026-000031');
        $maria->tags()->attach($tag);
        $this->ticket('João Lima', '5511988887777@c.us', '2026-000030')->tags()->attach($tag);
        $mariana->tags()->detach();

        $this->page()->set('tagId', $tag->id)->set('search', 'mari')->assertSee('Maria Souza')->assertDontSee('Mariana Dias')->assertDontSee('João Lima');
    }
}
