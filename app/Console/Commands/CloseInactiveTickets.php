<?php

namespace App\Console\Commands;

use App\Enums\ClosedReason;
use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\Ticket;
use App\Models\TicketEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CloseInactiveTickets extends Command
{
    protected $signature = 'app:close-inactive-tickets {--hours=24 : Horas corridas sem movimento}';

    protected $description = 'Encerra tickets abertos parados há mais de 24 h cuja última mensagem é nossa';

    /**
     * Ticket cuja última mensagem é do cliente espera resposta humana e nunca fecha sozinho. Idempotente: roda
     * em transação, com as linhas travadas, e o `UPDATE` só atinge ticket ainda aberto.
     */
    public function handle(): int
    {
        $limit = now()->subHours(max(1, (int) $this->option('hours')));

        $closed = DB::transaction(function () use ($limit): int {
            $ids = Ticket::query()
                ->where('status', '<>', TicketStatus::ENCERRADO->value)
                ->whereNotNull('last_outbound_at')
                ->where(fn ($q) => $q->whereNull('last_inbound_at')->orWhereColumn('last_outbound_at', '>', 'last_inbound_at'))
                ->whereRaw('greatest(last_inbound_at, last_outbound_at, opened_at) < ?', [$limit])
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isEmpty()) {
                return 0;
            }

            Ticket::query()->whereIn('id', $ids)->update([
                'status' => TicketStatus::ENCERRADO->value,
                'closed_at' => now(),
                'closed_reason' => ClosedReason::INATIVIDADE->value,
                'updated_at' => now(),
            ]);

            foreach ($ids as $id) {
                TicketEvent::create([
                    'ticket_id' => $id,
                    'type' => 'closed',
                    'payload' => ['reason' => ClosedReason::INATIVIDADE->value],
                ]);
            }

            Ticket::query()->whereIn('id', $ids)->each(fn (Ticket $ticket) => TicketUpdated::dispatch($ticket));

            return $ids->count();
        });

        $this->info("Tickets encerrados por inatividade: {$closed}");

        return self::SUCCESS;
    }
}
