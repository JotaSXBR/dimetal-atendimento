<?php

namespace App\Services\Bot\Effects;

use App\Models\BotSession;
use App\Models\Sector;
use App\Models\Tag;
use App\Models\User;
use App\Services\Bot\Simulation\SimulationState;
use App\Services\Inbox\BusinessHours;
use Closure;

/**
 * O bot do simulador: não grava nem envia nada, só registra o que faria na conversa de teste.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") a interface tem um método por tipo de efeito, de propósito
 */
final class SimulatedEffects implements BotEffects
{
    private const REASONS = [
        'sair' => 'o cliente pediu um atendente (#sair)',
        'timeout' => 'o cliente ficou em silêncio',
        'erros' => 'o cliente errou as respostas ou o fluxo não tem saída',
    ];

    public function __construct(private readonly SimulationState $state) {}

    public function say(string $text): void
    {
        if (trim($text) !== '') {
            $this->state->add('bot', $text);
        }
    }

    public function media(string $path, string $caption): void
    {
        $this->state->add('efeito', 'enviaria a mídia '.basename($path).($caption !== '' ? " (legenda: {$caption})" : ''));
    }

    public function wait(int $seconds): void
    {
        $this->state->add('efeito', "esperaria {$seconds} s");
    }

    public function saveContactField(string $field, string $value): void
    {
        if ($field === 'name') {
            $this->state->name = $value;
        }

        $this->state->add('efeito', "gravaria no contato: {$field} = {$value}");
    }

    public function tag(Tag $tag): void
    {
        $this->state->add('efeito', "etiquetaria \"{$tag->name}\"");
    }

    public function note(string $body): void
    {
        $this->state->add('efeito', "registraria a nota: {$body}");
    }

    public function close(): void
    {
        $this->state->outcome = SimulationState::CLOSED;
        $this->state->add('efeito', 'encerraria a conversa');
    }

    public function enterQueue(?Sector $sector, ?User $assignTo): ?int
    {
        $assigned = LiveEffects::assignable($assignTo, $sector);

        $this->state->outcome = SimulationState::IN_QUEUE;
        $this->state->add('efeito', 'entraria na fila de '.($sector !== null ? $sector->name : 'fila geral').($assigned !== null ? ", atribuído a {$assigned->name}" : ''));

        return $assigned?->id;
    }

    public function logHandoff(string $reason, ?Sector $sector, ?int $assignedUserId): void
    {
        if (isset(self::REASONS[$reason])) {
            $this->state->add('sistema', 'Encaminhado à fila: '.self::REASONS[$reason].'.');
        }
    }

    public function exclusive(BotSession $session, Closure $work): void
    {
        if ($session->state === BotSession::ACTIVE) {
            $work();
        }
    }

    public function save(BotSession $session): void
    {
        $this->state->capture($session);
    }

    public function entered(string $nodeId): void
    {
        $this->state->trail[] = $nodeId;
    }

    public function isOpen(): bool
    {
        return match ($this->state->clock) {
            'aberto' => true,
            'fechado' => false,
            default => app(BusinessHours::class)->isOpen(now()),
        };
    }

    public function contactName(): string
    {
        return $this->state->name;
    }

    /** Não há histórico de teste: o cliente do simulador nunca foi atendido antes. */
    public function returning(): bool
    {
        return false;
    }

    public function protocol(): string
    {
        return 'TESTE';
    }
}
