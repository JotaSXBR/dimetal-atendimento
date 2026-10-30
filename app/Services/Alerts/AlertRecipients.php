<?php

namespace App\Services\Alerts;

use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** A regra de quem recebe cada alerta. Sempre só usuários ativos. */
final class AlertRecipients
{
    /** @return Collection<int, User> */
    public function everyone(): Collection
    {
        return $this->active()->get();
    }

    /**
     * Quem vê a fila do ticket: o mesmo critério de {@see User::canHandleSector()}, numa consulta só.
     *
     * @return Collection<int, User>
     */
    public function queue(Ticket $ticket): Collection
    {
        return $this->active()->where(fn (Builder $q) => $this->seesQueue($q, $ticket))->get();
    }

    /** @return Collection<int, User> */
    public function sla(Ticket $ticket): Collection
    {
        return $this->active()->where(function (Builder $q) use ($ticket): void {
            $q->whereIn('role', [UserRole::SUPERVISOR->value, UserRole::ADMIN->value]);

            if ($ticket->assigned_user_id !== null) {
                $q->orWhere('id', $ticket->assigned_user_id);
            } else {
                $q->orWhere(fn (Builder $sub) => $this->seesQueue($sub, $ticket));
            }
        })->get();
    }

    /** @return Builder<User> */
    private function active(): Builder
    {
        return User::query()->where('active', true);
    }

    /** @param  Builder<User>  $q */
    private function seesQueue(Builder $q, Ticket $ticket): void
    {
        $q->where('role', '<>', UserRole::ATENDENTE->value);

        if ($ticket->sector_id === null) {
            $q->orWhere('role', UserRole::ATENDENTE->value);
        } else {
            $q->orWhereHas('sectors', fn (Builder $s) => $s->whereKey($ticket->sector_id));
        }
    }
}
