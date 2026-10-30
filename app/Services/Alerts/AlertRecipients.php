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

    /**
     * Tickets com SLA estourado cujo alerta este usuário recebe: a regra de {@see self::sla()} vista do outro lado
     * (o responsável, ou a fila quando não há responsável; supervisor e admin, todos). Alimenta a faixa e o som repetido.
     *
     * @return Builder<Ticket>
     */
    public function breachedFor(User $user): Builder
    {
        $query = Ticket::query()->open()->whereNotNull('waiting_since')->where('sla_due_at', '<=', now());

        if (! $user->active) {
            return $query->whereRaw('false');
        }

        if ($user->canSeeAllSectors()) {
            return $query;
        }

        $sectors = $user->sectors()->select('sectors.id');

        return $query->where(fn (Builder $q) => $q
            ->where('assigned_user_id', $user->id)
            ->orWhere(fn (Builder $queue) => $queue
                ->whereNull('assigned_user_id')
                ->where(fn (Builder $sector) => $sector->whereNull('sector_id')->orWhereIn('sector_id', $sectors))));
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
