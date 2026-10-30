<?php

namespace App\Services\Inbox;

use App\Enums\CloseOutcome;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A consulta do histórico: tickets encerrados que o usuário pode ver, filtrados. O texto das mensagens só entra na busca
 * com {@see self::MIN_TEXT} caracteres ou mais (termo curto casa com tudo e o índice de trigramas não o atende).
 */
final class HistorySearch
{
    public const MIN_TEXT = 3;

    /**
     * `$from` e `$to` são datas `Y-m-d` de Brasília, inclusive nas duas pontas. O filtro de atendente só vale para quem vê
     * todos os setores.
     *
     * @return Builder<Ticket>
     */
    public function query(User $user, string $q = '', ?string $from = null, ?string $to = null, ?int $sectorId = null, ?int $userId = null, ?string $outcome = null): Builder
    {
        $query = Ticket::query()->inHistoryOf($user);
        $q = trim($q);

        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';

            $query->where(function (Builder $match) use ($q, $like): void {
                $match->whereHas('contact', fn (Builder $contact) => $contact->search($q))
                    ->orWhereRaw('tickets.protocol ilike ?', [$like]);

                if (mb_strlen($q) >= self::MIN_TEXT) {
                    $match->orWhereExists(fn ($message) => $message->selectRaw('1')->from('messages')
                        ->whereColumn('messages.ticket_id', 'tickets.id')
                        ->whereRaw('messages.body ilike ?', [$like]));
                }
            });
        }

        $tz = config()->string('app.display_timezone');
        $appTz = config()->string('app.timezone');

        $query
            ->when($from !== null && $from !== '', fn (Builder $b) => $b->where('closed_at', '>=', CarbonImmutable::parse((string) $from, $tz)->startOfDay()->setTimezone($appTz)))
            ->when($to !== null && $to !== '', fn (Builder $b) => $b->where('closed_at', '<=', CarbonImmutable::parse((string) $to, $tz)->endOfDay()->setTimezone($appTz)))
            ->when($sectorId !== null, fn (Builder $b) => $b->where('sector_id', $sectorId))
            ->when($userId !== null && $user->canSeeAllSectors(), fn (Builder $b) => $b->where('assigned_user_id', $userId))
            ->when($outcome !== null && CloseOutcome::tryFrom($outcome) !== null, fn (Builder $b) => $b->where('close_outcome', $outcome));

        return $query->orderByDesc('closed_at')->orderByDesc('tickets.id');
    }
}
