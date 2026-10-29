<?php

namespace App\Jobs;

use App\Enums\MessageDirection;
use App\Events\MessageStatusChanged;
use App\Models\Contact;
use App\Models\Message;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

        if ($file === null) {
            throw new RuntimeException('O WAHA ainda não tem o arquivo da mensagem '.$message->id);
        }

        $message->update([
            'media_ref' => $file['filename'],
            'media_name' => $file['filename'],
            'media_mime' => $file['mimetype'] === null ? $message->media_mime : trim(explode(';', $file['mimetype'])[0]),
        ]);

        MessageStatusChanged::dispatch($message);
    }
}
