<?php

namespace Tests\Feature\Search;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Contacts\Index as ContactsIndex;
use App\Livewire\Search\Palette;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** A busca Ctrl+K: telas, contatos e protocolos, sempre pelo que o usuário já pode ver. */
class PaletteTest extends TestCase
{
    use RefreshDatabase;

    private function palette(User $user, string $q = ''): Testable
    {
        return Livewire::actingAs($user)->test(Palette::class)->set('q', $q);
    }

    private function atendente(?Sector $sector = null): User
    {
        $user = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $sector === null || $user->sectors()->attach($sector);

        return $user;
    }

    /** @return list<string> */
    private function labels(Testable $palette, string $group): array
    {
        return array_column($palette->instance()->results()[$group], 'label');
    }

    public function test_sem_busca_so_as_telas_que_o_papel_abre(): void
    {
        $admin = $this->labels($this->palette(User::factory()->create(['role' => UserRole::ADMIN])), 'screens');
        $atendente = $this->labels($this->palette($this->atendente()), 'screens');

        $this->assertContains('Relatórios', $admin);
        $this->assertContains('Fila', $atendente);
        $this->assertNotContains('Relatórios', $atendente);
        $this->assertNotContains('Setores', $atendente);
    }

    public function test_a_busca_filtra_telas_sem_acento_nem_caixa(): void
    {
        $palette = $this->palette(User::factory()->create(['role' => UserRole::ADMIN]), 'RELATORIOS');

        $this->assertSame(['Relatórios'], $this->labels($palette, 'screens'));
    }

    public function test_acha_contato_por_nome_e_por_telefone_e_porcento_nao_e_curinga(): void
    {
        $ana = Contact::factory()->create(['name' => 'Ana Paula', 'wa_id' => '5516997524950@c.us']);
        Contact::factory()->create(['name' => 'Bruno', 'wa_id' => '5511911112222@c.us']);
        $user = $this->atendente();

        $this->assertSame(['Ana Paula'], $this->labels($this->palette($user, 'ana p'), 'contacts'));
        $this->assertSame(['Ana Paula'], $this->labels($this->palette($user, '99752'), 'contacts'));
        $this->assertSame([], $this->labels($this->palette($user, '%'), 'contacts'));
        $this->assertSame(route('contatos', ['contato' => $ana->id]), $this->palette($user, 'ana')->instance()->results()['contacts'][0]['url']);
    }

    public function test_protocolo_so_aparece_para_quem_ve_o_ticket(): void
    {
        $channel = Channel::factory()->create();
        $mine = Sector::factory()->create();
        $other = Sector::factory()->create();
        $visible = Ticket::factory()->create(['channel_id' => $channel->id, 'sector_id' => $mine->id, 'status' => TicketStatus::AGUARDANDO, 'protocol' => 'PROT-1111']);
        Ticket::factory()->create(['channel_id' => $channel->id, 'sector_id' => $other->id, 'status' => TicketStatus::AGUARDANDO, 'protocol' => 'PROT-2222']);
        $user = $this->atendente($mine);

        $found = $this->palette($user, 'PROT');

        $this->assertSame(['PROT-1111'], $this->labels($found, 'tickets'));
        $this->assertSame(route('inbox', ['ticket' => $visible->id]), $found->instance()->results()['tickets'][0]['url']);
        $this->assertSame([], $this->labels($this->palette($user, 'PROT-2222'), 'tickets'));
    }

    public function test_usuario_inativo_nao_acha_nada(): void
    {
        Contact::factory()->create(['name' => 'Ana']);
        $user = User::factory()->create(['role' => UserRole::ADMIN, 'active' => false]);

        $results = $this->palette($user, 'ana')->instance()->results();

        $this->assertSame([], $results['contacts']);
        $this->assertSame([], $results['tickets']);
    }

    public function test_sem_resultado_mostra_nada_encontrado_e_cada_grupo_tem_no_maximo_5(): void
    {
        Contact::factory()->count(8)->create(['name' => 'Maria Teste']);
        $user = $this->atendente();

        $this->palette($user, 'zzzz')->assertSee('Nada encontrado');
        $this->assertCount(5, $this->labels($this->palette($user, 'maria'), 'contacts'));
    }

    public function test_contatos_abre_a_ficha_pelo_parametro_contato(): void
    {
        $contact = Contact::factory()->create();
        $user = $this->atendente();

        Livewire::withQueryParams(['contato' => $contact->id])->actingAs($user)->test(ContactsIndex::class)
            ->assertSet('showCard', true)->assertSet('selectedId', $contact->id);

        Livewire::withQueryParams(['contato' => 999999])->actingAs($user)->test(ContactsIndex::class)->assertSet('showCard', false);
    }

    public function test_o_markup_e_um_combobox_com_listbox_e_opcoes_com_id(): void
    {
        $html = $this->palette(User::factory()->create(['role' => UserRole::ADMIN]), 'rel')->html();

        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString('aria-controls="palette-list"', $html);
        $this->assertStringContainsString('id="palette-list"', $html);
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringContainsString('id="palette-option-0"', $html);
        $this->assertStringContainsString('role="group"', $html);
    }

    public function test_nada_encontrado_fica_fora_do_listbox_e_e_anunciado(): void
    {
        $html = $this->palette($this->atendente(), 'zzzz')->html();

        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringNotContainsString('id="palette-option-0"', $html);
    }

    public function test_fechar_a_ficha_tira_o_contato_da_url(): void
    {
        $contact = Contact::factory()->create();

        Livewire::withQueryParams(['contato' => $contact->id])->actingAs($this->atendente())->test(ContactsIndex::class)
            ->assertSet('showCard', true)
            ->set('showCard', false)
            ->assertSet('selectedId', null)
            ->assertSet('showCard', false);
    }

    public function test_o_parametro_contato_e_ligado_a_url(): void
    {
        $property = new \ReflectionProperty(ContactsIndex::class, 'selectedId');
        $attributes = array_map(fn (\ReflectionAttribute $a): string => $a->getName(), $property->getAttributes());

        $this->assertContains(\Livewire\Attributes\Url::class, $attributes);
    }
}
