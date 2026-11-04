<?php

namespace App\Services\Inbox;

use App\Events\MessageStatusChanged;
use App\Models\Message;
use App\Models\MessageEdit;
use App\Models\MessageReaction;
use App\Models\WahaEvent;
use App\Services\Waha\WahaChat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Aplica em uma mensagem que já existe uma reação, uma edição ou um apagamento (`message.reaction`, `message.edited`,
 * `message.revoked`). Devolve `false` quando a mensagem de destino ainda não foi gravada (o job tenta de novo, como no ack)
 * e `true` nos demais casos, inclusive payload sem o que precisamos. Nenhum dos três mexe no ticket.
 */
class ApplyMessageChange
{
    public function reaction(WahaEvent $event): bool
    {
        $data = $this->payload($event);

        if ($this->inIgnoredChat($data)) {
            return true;
        }

        $messageId = data_get($data, 'reaction.messageId');
        $text = data_get($data, 'reaction.text');

        if (! is_string($messageId) || ! is_string($text)) {
            return true;
        }

        $target = $this->find($event, $messageId);

        if ($target === null) {
            return false;
        }

        $by = ($data['fromMe'] ?? false) ? 'me' : 'contact';

        if ($text === '') {
            MessageReaction::query()->where('message_id', $target->id)->where('by', $by)->delete();
        } else {
            $at = $this->time($data['timestamp'] ?? null);
            MessageReaction::query()->upsert(
                [['message_id' => $target->id, 'by' => $by, 'emoji' => Str::limit($text, 32, ''), 'reacted_at' => $at, 'created_at' => $at, 'updated_at' => $at]],
                ['message_id', 'by'],
                ['emoji', 'reacted_at', 'updated_at'],
            );
        }

        MessageStatusChanged::dispatch($target);

        return true;
    }

    public function edited(WahaEvent $event): bool
    {
        $data = $this->payload($event);

        if ($this->inIgnoredChat($data)) {
            return true;
        }

        $messageId = $data['editedMessageId'] ?? null;
        $body = $data['body'] ?? null;

        if (! is_string($messageId) || ! is_string($body)) {
            return true;
        }

        $target = $this->find($event, $messageId);

        if ($target === null) {
            return false;
        }

        $actionId = is_string($data['id'] ?? null) ? Message::waKey($data['id']) : null;

        // Trava a linha: o mesmo evento repetido pelo WAHA (mesmo tarde, depois de outras edições) não vale duas vezes.
        $changed = DB::transaction(function () use ($target, $body, $actionId): bool {
            $locked = Message::query()->lockForUpdate()->findOrFail($target->id);

            if ($locked->body === $body || ($actionId !== null && MessageEdit::query()->where('action_id', $actionId)->exists())) {
                return false;
            }

            MessageEdit::query()->create(['message_id' => $locked->id, 'previous_body' => $locked->body, 'edited_at' => now(), 'action_id' => $actionId]);
            $locked->update(['body' => $body, 'edited_at' => now()]);

            return true;
        });

        if ($changed) {
            MessageStatusChanged::dispatch($target->refresh());
        }

        return true;
    }

    public function revoked(WahaEvent $event): bool
    {
        $data = $this->payload($event);

        if ($this->inIgnoredChat($data)) {
            return true;
        }

        $messageId = $data['revokedMessageId'] ?? null;

        if (! is_string($messageId)) {
            return true;
        }

        $target = $this->find($event, $messageId);

        if ($target === null) {
            return false;
        }

        $target->revoked_at ??= now();
        $target->save();
        MessageStatusChanged::dispatch($target);

        return true;
    }

    /**
     * Grupos não são ingeridos, então o destino nunca existiria e o evento ficaria voltando para a fila à toa.
     *
     * @param  array<mixed>  $data
     */
    private function inIgnoredChat(array $data): bool
    {
        foreach (['from', 'to', 'after.from', 'after.to', 'reaction.messageId'] as $path) {
            $value = data_get($data, $path);

            if (is_string($value) && WahaChat::isIgnored($value)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<mixed> */
    private function payload(WahaEvent $event): array
    {
        $data = $event->payload['payload'] ?? null;

        return is_array($data) ? $data : [];
    }

    private function find(WahaEvent $event, string $waId): ?Message
    {
        return Message::query()->where('channel_id', $event->channel_id)->where('wa_message_id', Message::waKey($waId))->first();
    }

    private function time(mixed $timestamp): CarbonImmutable
    {
        return is_numeric($timestamp) && (int) $timestamp > 0 ? CarbonImmutable::createFromTimestampUTC((int) $timestamp) : CarbonImmutable::now();
    }
}
