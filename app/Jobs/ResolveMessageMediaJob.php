<?php

namespace App\Jobs;

use App\Enums\MessageDirection;
use App\Events\MessageStatusChanged;
use App\Models\Contact;
use App\Models\Message;
use App\Services\Inbox\IncomingMedia;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Completa a referência da mídia quando o evento do WAHA não a trouxe. Esgotadas as tentativas a mensagem fica sem
 * arquivo ("Mídia indisponível"); o texto nunca se perde por causa do anexo.
 */
class ResolveMessageMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 60];

    public function __construct(public readonly int $messageId) {}

    public function handle(): void
    {
        $message = Message::query()->find($this->messageId);

        if ($message === null || ! $message->hasMedia() || $message->media_ref !== null) {
            return;
        }

        $contact = Contact::findOrFail($message->contact_id);
        $chatId = $contact->wa_id ?? (string) $contact->lid;
        $serialized = ($message->direction === MessageDirection::OUT ? 'true' : 'false').'_'.$chatId.'_'.$message->wa_message_id;

        $file = (new WahaClient($message->channel()->firstOrFail()))->downloadMessageMedia($chatId, $serialized);
        $ref = $file === null ? null : IncomingMedia::safeRef($file['filename']);

        if ($file === null || $ref === null) {
            throw new RuntimeException('O WAHA ainda não tem um arquivo válido para a mensagem '.$message->id);
        }

        $message->update([
            'media_ref' => $ref,
            'media_name' => $ref,
            'media_mime' => $file['mimetype'] === null ? $message->media_mime : Str::limit(trim(explode(';', $file['mimetype'])[0]), 100, ''),
        ]);

        MessageStatusChanged::dispatch($message);
    }
}
