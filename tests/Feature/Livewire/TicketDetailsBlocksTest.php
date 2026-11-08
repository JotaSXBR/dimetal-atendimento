<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\History\Index as History;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TicketDetailsBlocksTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(TicketStatus $status): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => (Channel::query()->first() ?? Channel::factory()->create())->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => $status,
            'closed_at' => $status === TicketStatus::ENCERRADO ? now() : null,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    private function inboxHtml(): string
    {
        return Livewire::actingAs($this->admin())->test(Inbox::class)->call('select', $this->ticket(TicketStatus::AGUARDANDO)->id)->html();
    }

    /** A linha do tempo só existe no painel do Histórico. */
    private function historyHtml(): string
    {
        return Livewire::actingAs($this->admin())->test(History::class)->call('select', $this->ticket(TicketStatus::ENCERRADO)->id)->html();
    }

    /** O `<details>` (abertura) do bloco com esse data-testid. */
    private function tag(string $html, string $testid): string
    {
        $this->assertSame(1, preg_match('/<details[^>]*data-testid="'.$testid.'"[^>]*>/', $html, $m), "bloco {$testid} ausente");

        return $m[0];
    }

    public function test_bloco_do_ticket_vem_aberto_e_linha_do_tempo_fechada(): void
    {
        $this->assertMatchesRegularExpression('/\sopen[\s>]/', $this->tag($this->inboxHtml(), 'details-ticket'));
        $this->assertDoesNotMatchRegularExpression('/\sopen[\s>]/', $this->tag($this->historyHtml(), 'details-timeline'));
    }

    public function test_blocos_ignoram_o_morph_do_livewire(): void
    {
        $this->assertStringContainsString('wire:ignore.self', $this->tag($this->inboxHtml(), 'details-ticket'));
        $this->assertStringContainsString('wire:ignore.self', $this->tag($this->historyHtml(), 'details-timeline'));
    }
}
