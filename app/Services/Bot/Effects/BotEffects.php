<?php

namespace App\Services\Bot\Effects;

use App\Models\BotSession;
use App\Models\Sector;
use App\Models\Tag;
use App\Models\User;
use Closure;

/**
 * Tudo que sai do motor do bot. A implementação de produção (`LiveEffects`) faz o que o motor sempre fez; a do simulador só
 * registra. O motor e os blocos não sabem qual das duas usam.
 */
interface BotEffects
{
    /** Texto já renderizado; vazio é ignorado. */
    public function say(string $text): void;

    /** Arquivo do disco `local` (caminho relativo) e legenda já renderizada. */
    public function media(string $path, string $caption): void;

    /** "Digitando…" e pausa; os segundos já vêm limitados pelo bloco. */
    public function wait(int $seconds): void;

    public function saveContactField(string $field, string $value): void;

    public function tag(Tag $tag): void;

    public function note(string $body): void;

    public function close(): void;

    /** Entra na fila (setor ou fila geral, se nulo) e tenta passar o ticket à pessoa; devolve o id de quem assumiu. */
    public function enterQueue(?Sector $sector, ?User $assignTo): ?int;

    public function logHandoff(string $reason, ?Sector $sector, ?int $assignedUserId): void;

    /**
     * Roda `$work` com a sessão trancada e só se ela ainda estiver ativa (entregar à fila vale uma vez por sessão).
     *
     * @param  Closure(): void  $work
     */
    public function exclusive(BotSession $session, Closure $work): void;

    /** Persiste a sessão (o simulador não persiste). */
    public function save(BotSession $session): void;

    /** O motor entrou no bloco (só o simulador usa, para a trilha). */
    public function entered(string $nodeId): void;

    public function isOpen(): bool;

    /** Vazio se o contato não tem nome. */
    public function contactName(): string;

    /** Já houve outro ticket deste contato. */
    public function returning(): bool;

    public function protocol(): string;
}
