<?php

namespace Tests\Feature\History;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\History\Index;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class HistoryPaginationTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('pt_BR');
        $this->channel = Channel::factory()->create();
    }

    private function closed(string $name, int $minutesAgo): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => $this->channel->id,
            'contact_id' => Contact::factory()->create(['name' => $name])->id,
            'status' => TicketStatus::ENCERRADO,
            'closed_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    private function page(): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->test(Index::class);
    }

    public function test_a_lista_nao_faz_count_do_total(): void
    {
        $this->closed('Cliente 01', 1);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->page();

        $counts = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains(strtolower($q['query']), 'count(*)') && str_contains($q['query'], '"tickets"'));
        DB::disableQueryLog();

        $this->assertSame([], array_values($counts), 'a contagem do total custa 24 a 51 ms com 1 milhão de tickets e cresce com a tabela');
    }

    public function test_a_pagina_2_traz_os_tickets_seguintes_sem_repetir_nem_pular(): void
    {
        foreach (range(1, 26) as $i) {
            $this->closed(sprintf('Cliente %02d', $i), $i);
        }

        $component = $this->page();
        $component->assertSee('Cliente 01')->assertSee('Cliente 25')->assertDontSee('Cliente 26');

        $component->call('nextPage')->assertSee('Cliente 26')->assertDontSee('Cliente 01')->assertDontSee('Cliente 25');
    }

    public function test_o_paginador_esta_em_portugues_e_so_oferece_o_que_existe(): void
    {
        foreach (range(1, 26) as $i) {
            $this->closed(sprintf('Cliente %02d', $i), $i);
        }

        $first = $this->page();
        $first->assertSee('Próximo')->assertDontSee('Next');

        $second = $first->call('nextPage');
        $second->assertSee('Anterior')->assertDontSee('Previous');
    }

    public function test_sem_proxima_pagina_quando_cabe_em_uma(): void
    {
        $this->closed('Cliente 01', 1);

        $this->page()->assertDontSee('Próxima')->assertDontSee('Anterior');
    }
}
