<?php

namespace App\Jobs;

use App\Enums\MessageDirection;
use App\Events\MessageStatusChanged;
use App\Models\Contact;
use App\Models\Message;
use App\Services\Inbox\IncomingMedia;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Descobre qual arquivo do WAHA é o desta mídia e grava o nome que existe de fato. O WAHA guarda o arquivo como
 * `{id serializado}.{extensão}` e o `media.filename` do evento nem sempre coincide, então os nomes candidatos são
 * testados no WAHA. Esgotadas as tentativas a mensagem fica sem arquivo ("Mídia indisponível"); o texto nunca se
 * perde por causa do anexo.
 */
class ResolveMessageMediaJob implements ShouldQueue
{
    use Queueable;

    private const EXTENSIONS = [
        'image/jpeg' => ['.jpeg', '.jpg'],
        'image/png' => ['.png'],
        'image/webp' => ['.webp'],
        'image/gif' => ['.gif'],
        'video/mp4' => ['.mp4'],
        'audio/ogg' => ['.ogg', '.oga'],
        'audio/mpeg' => ['.mp3'],
        'audio/mp4' => ['.m4a'],
        'audio/aac' => ['.aac'],
        'video/webm' => ['.webm'],
        'application/pdf' => ['.pdf'],
    ];

    public int $tries = 4;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 60];

    public function __construct(public readonly int $messageId) {}

    public function handle(): void
    {
        $message = Message::query()->find($this->messageId);

        if ($message === null || ! $message->hasMedia()) {
            return;
        }

        $contact = Contact::findOrFail($message->contact_id);
        $chatId = $contact->wa_id ?? (string) $contact->lid;
        $serialized = ($message->direction === MessageDirection::OUT ? 'true' : 'false').'_'.$chatId.'_'.$message->wa_message_id;

        $client = new WahaMedia(new WahaClient($message->channel()->firstOrFail()));
        $file = $client->downloadMessageMedia($chatId, $serialized);

        if ($file === null) {
            throw new RuntimeException('O WAHA ainda não trouxe a mídia da mensagem '.$message->id);
        }

        $mime = $file['mimetype'] === null ? $message->media_mime : Str::limit(trim(explode(';', $file['mimetype'])[0]), 100, '');
        $names = $this->candidates($message, $file['filename'], $serialized, $mime);
        $ref = $client->firstStoredFile($names);

        if ($ref === null) {
            Log::warning('Nenhum arquivo do WAHA corresponde à mídia', ['message' => $message->id, 'candidatos' => $names, 'mime' => $mime]);

            throw new RuntimeException('O WAHA ainda não tem o arquivo da mensagem '.$message->id);
        }

        $message->update(['media_ref' => $ref, 'media_name' => $message->media_name ?? $ref, 'media_mime' => $mime]);

        MessageStatusChanged::dispatch($message);
    }

    /**
     * Nomes a testar, do mais provável ao menos: o que já está gravado, o que o WAHA informou, o id serializado e o id
     * puro (os dois com a extensão do arquivo ou do tipo). Só entram nomes que não saem do diretório de arquivos.
     *
     * @return list<string>
     */
    private function candidates(Message $message, ?string $reported, string $serialized, ?string $mime): array
    {
        $given = [$message->media_ref, $reported];
        $extensions = array_filter([
            ...array_map(fn (?string $name): string => (string) preg_replace('/\A.*?(\.[A-Za-z0-9]+)\z/', '$1', (string) $name), array_filter($given, fn (?string $name): bool => $name !== null && str_contains($name, '.'))),
            ...($mime === null ? [] : self::EXTENSIONS[$mime] ?? []),
        ]);

        $names = [...$given];

        foreach (array_unique($extensions) as $ext) {
            $names[] = $serialized.$ext;
            $names[] = $message->wa_message_id.$ext;
        }

        return array_values(array_unique(array_filter(array_map(IncomingMedia::safeRef(...), $names))));
    }
}
