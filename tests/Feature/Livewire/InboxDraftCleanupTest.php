<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InboxDraftCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_responde_so_os_encerrados_que_o_usuario_ve(): void
    {
        $channel = Channel::factory()->create();
        $user = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $other = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $open = Ticket::factory()->create(['channel_id' => $channel->id, 'status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $user->id]);
        $closed = Ticket::factory()->create(['channel_id' => $channel->id, 'status' => TicketStatus::ENCERRADO, 'assigned_user_id' => $user->id]);
        $foreign = Ticket::factory()->create(['channel_id' => $channel->id, 'status' => TicketStatus::ENCERRADO, 'assigned_user_id' => $other->id]);

        Livewire::actingAs($user)->test(Inbox::class)
            ->call('closedDraftTickets', [$open->id, $closed->id, $foreign->id])
            ->assertReturned([$closed->id]);
    }

    public function test_usuario_inativo_nao_recebe_nenhum_id(): void
    {
        $closed = Ticket::factory()->create(['status' => TicketStatus::ENCERRADO]);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN, 'active' => false]))->test(Inbox::class)
            ->call('closedDraftTickets', [$closed->id])
            ->assertReturned([]);
    }

    public function test_ignora_ids_invalidos_e_corta_em_200(): void
    {
        $closed = Ticket::factory()->create(['status' => TicketStatus::ENCERRADO]);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Inbox::class)
            ->call('closedDraftTickets', ['x', -1, 0, 1.5, null])
            ->assertReturned([])
            ->call('closedDraftTickets', [$closed->id, 'x'])
            ->assertReturned([$closed->id])
            // O corte em 200 vem antes da consulta: o id válido na posição 201 não entra.
            ->call('closedDraftTickets', [...range(100000, 100199), $closed->id])
            ->assertReturned([]);
    }
}
