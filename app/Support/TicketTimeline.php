<?php

namespace App\Support;

use App\Models\Sector;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Os eventos de um ticket em frases ("Ana transferiu de Comercial para Financeiro"). Os nomes de setor e de pessoa vêm de uma
 * consulta por tipo para a lista toda, não uma por evento.
 */
final class TicketTimeline
{
    /** Eventos de conversa: a conversa já está na tela, então a linha do tempo só mostra o que mudou o ticket. */
    private const CONVERSATION = ['message_in', 'message_out', 'after_hours_reply', 'media_purged'];

    private const VERBS = [
        'opened' => 'abriu o ticket',
        'claimed' => 'assumiu',
        'closed' => 'encerrou',
        'transferred' => 'transferiu',
        'reassigned' => 'reatribuiu',
        'taken_over' => 'tomou o ticket',
        'merged' => 'mesclou o ticket',
        'sla_breached' => 'estourou o SLA',
    ];

    /** @var Collection<int, string> */
    private Collection $sectors;

    /** @var Collection<int, string> */
    private Collection $users;

    /** @param  Collection<int, TicketEvent>  $events */
    public function __construct(Collection $events)
    {
        $ids = fn (array $keys): array => $events
            ->flatMap(fn (TicketEvent $event) => array_map(fn (string $key) => ($event->payload ?? [])[$key] ?? null, $keys))
            ->filter(fn ($id) => is_int($id))
            ->unique()
            ->values()
            ->all();

        $this->sectors = Sector::query()->whereIn('id', $ids(['from_sector', 'to_sector']))->pluck('name', 'id');
        $this->users = User::query()->whereIn('id', $ids(['from_user', 'to_user']))->pluck('name', 'id');
    }

    /**
     * @param  Collection<int, TicketEvent>  $events
     * @return Collection<int, TicketEvent>
     */
    public static function changes(Collection $events): Collection
    {
        return $events->reject(fn (TicketEvent $event): bool => in_array($event->type, self::CONVERSATION, true))->values();
    }

    /** O que aconteceu, sem o nome de quem fez: "de Comercial para Financeiro · nota". */
    public function detail(TicketEvent $event): string
    {
        $payload = $event->payload ?? [];

        return match ($event->type) {
            'transferred' => implode(' · ', array_filter([
                'de '.$this->sector($payload['from_sector'] ?? null).' para '.$this->sector($payload['to_sector'] ?? null),
                is_string($payload['note'] ?? null) ? $payload['note'] : null,
            ])),
            'reassigned', 'taken_over' => 'para '.$this->user($payload['to_user'] ?? null),
            'merged' => 'em outro ticket do mesmo cliente',
            'closed', 'sla_breached' => TicketEventText::detail($event),
            // Tipo que esta tela não conhece: a auditoria não esconde o dado, mostra o conteúdo como veio.
            default => array_key_exists($event->type, TicketEventText::TYPES) || $payload === [] ? '' : (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
        };
    }

    /** "Ana transferiu de Comercial para Financeiro"; sem ator, quem agiu foi o sistema. */
    public function sentence(TicketEvent $event): string
    {
        $verb = self::VERBS[$event->type] ?? mb_strtolower(TicketEventText::label($event->type));

        return trim(($event->actor?->name ?? 'Sistema').' '.$verb.' '.$this->detail($event));
    }

    private function sector(mixed $id): string
    {
        return is_int($id) ? ($this->sectors->get($id) ?? 'setor removido') : 'Fila geral';
    }

    private function user(mixed $id): string
    {
        return is_int($id) ? ($this->users->get($id) ?? 'usuário removido') : 'ninguém';
    }
}
