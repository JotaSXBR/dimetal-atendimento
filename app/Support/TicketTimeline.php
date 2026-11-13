<?php

namespace App\Support;

use App\Enums\ClosedReason;
use App\Enums\CloseOutcome;
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
        'message_reacted' => 'reagiu a uma mensagem',
        'message_edited' => 'editou uma mensagem',
        'message_revoked_by_agent' => 'apagou uma mensagem para todos',
        'note_deleted' => 'apagou uma nota interna',
        'tag_added' => 'adicionou a etiqueta',
        'tag_removed' => 'removeu a etiqueta',
        'opened_by_agent' => 'iniciou a conversa',
    ];

    /** Por que o bot passou o ticket para a equipe. `fluxo` (o bloco Transferir) e motivo desconhecido não levam sufixo. */
    private const HANDOFF_REASONS = [
        'sair' => 'cliente pediu atendente',
        'erros' => 'respostas inválidas',
        'timeout' => 'sem resposta do cliente',
        'sem_workflow' => 'sem automação ativa',
    ];

    /** @var Collection<int, string> */
    private Collection $sectors;

    /** @var Collection<int, string> */
    private Collection $users;

    /** @param  Collection<int, TicketEvent>  $events */
    public function __construct(Collection $events)
    {
        $sectorIds = [];
        $userIds = [];

        foreach ($events as $event) {
            foreach ($event->payload ?? [] as $key => $id) {
                if (! is_int($id)) {
                    continue;
                }

                if ($key === 'from_sector' || $key === 'to_sector' || $key === 'sector_id') {
                    $sectorIds[] = $id;
                } elseif ($key === 'from_user' || $key === 'to_user') {
                    $userIds[] = $id;
                }
            }
        }

        $this->sectors = Sector::query()->whereIn('id', $sectorIds)->get()->mapWithKeys(fn (Sector $sector): array => [$sector->id => $sector->name]);
        $this->users = User::query()->whereIn('id', $userIds)->get()->mapWithKeys(fn (User $user): array => [$user->id => $user->name]);
    }

    /**
     * O que mudou o ticket, para quem lê o histórico: sem a conversa e sem tipo que ainda não tem texto (a auditoria lista todos).
     *
     * @param  Collection<int, TicketEvent>  $events
     * @return Collection<int, TicketEvent>
     */
    public static function changes(Collection $events): Collection
    {
        return $events->reject(fn (TicketEvent $event): bool => in_array($event->type, self::CONVERSATION, true) || ! array_key_exists($event->type, TicketEventText::TYPES))->values();
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
            'closed' => $this->closedDetail($payload),
            'sla_breached' => TicketEventText::detail($event),
            'tag_added', 'tag_removed' => is_string($payload['tag'] ?? null) ? $payload['tag'] : '',
            'message_reacted' => is_string($payload['emoji'] ?? null) ? 'com '.$payload['emoji'] : '(reação removida)',
            // Sem formato próprio: o payload é dado interno e nunca vira texto na tela.
            default => '',
        };
    }

    /** "Bot encaminhou para Comercial (cliente pediu atendente)". */
    private function botHandoff(TicketEvent $event): string
    {
        $payload = $event->payload ?? [];
        $reason = is_string($payload['reason'] ?? null) ? (self::HANDOFF_REASONS[$payload['reason']] ?? null) : null;
        $sentence = 'Bot encaminhou para '.$this->sector($payload['sector_id'] ?? null);

        return $reason === null ? $sentence : $sentence.' ('.$reason.')';
    }

    /**
     * "Ana transferiu de Comercial para Financeiro". Sem ator, o evento é do sistema (ou do cliente) e lê como rótulo:
     * "SLA estourado (esperando desde …)", "Ticket aberto", "Encerrado por inatividade".
     */
    public function sentence(TicketEvent $event): string
    {
        if ($event->type === 'bot_handoff') {
            return $this->botHandoff($event);
        }

        $detail = $this->detail($event);
        $actor = $event->actor_user_id === null ? null : $event->actor;

        if ($actor === null) {
            $label = TicketEventText::label($event->type);

            return match (true) {
                $detail === '' => $label,
                $event->type === 'closed' && str_starts_with($detail, 'por ') => $label.' '.$detail,
                default => $label.' ('.$detail.')',
            };
        }

        $verb = self::VERBS[$event->type] ?? mb_strtolower(TicketEventText::label($event->type));

        return trim($actor->name.' '.$verb.($detail === '' ? '' : ($event->type === 'closed' ? ': ' : ' ').$detail));
    }

    /**
     * Motivo e resumo do encerramento. O motivo automático só entra quando não houve motivo escolhido pelo atendente.
     *
     * @param  array<string, mixed>  $payload
     */
    private function closedDetail(array $payload): string
    {
        $outcome = is_string($payload['outcome'] ?? null) ? CloseOutcome::tryFrom($payload['outcome'])?->label() : null;
        $summary = is_string($payload['summary'] ?? null) ? $payload['summary'] : null;
        $automatic = $outcome === null && ($payload['reason'] ?? null) === ClosedReason::INATIVIDADE->value ? 'por inatividade' : null;

        return implode(' · ', array_filter([$outcome, $summary, $automatic]));
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
