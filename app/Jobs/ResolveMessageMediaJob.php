<?php

namespace App\Jobs;

use App\Enums\MessageDirection;
use App\Events\MessageStatusChanged;
use App\Models\Contact;
use App\Models\Message;
use App\Services\Inbox\IncomingMedia;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaMedia;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;
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

    public function __construct(public readonly int $messageId, string $queue = 'inbound')
    {
        $this->onQueue($queue);
    }

    public function handle(): void
    {
        $message = Message::query()->find($this->messageId);

        if ($message === null || ! $message->hasMedia()) {
            return;
        }

        // Já copiada (rodada anterior ou reentrega): nada a fazer.
        if ($message->media_path !== null && Message::mediaDisk()->exists($message->media_path)) {
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

        // Falha de cópia relança: o job tenta de novo, e esgotadas as tentativas a mensagem segue abrindo pelo WAHA (`media_ref`).
        $this->copyToDisk($message, $client, $ref);

        MessageStatusChanged::dispatch($message);
    }

    /** Guarda no disco de mídia o arquivo que o WAHA tem, lido em stream (o WAHA só o mantém por alguns dias). */
    private function copyToDisk(Message $message, WahaMedia $client, string $ref): void
    {
        $response = $client->streamFile($ref, null);

        if ($response === null) {
            throw new RuntimeException('O WAHA não entregou o arquivo da mensagem '.$message->id);
        }

        $extension = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', pathinfo($ref, PATHINFO_EXTENSION)));
        $key = 'inbound/'.$message->channel_id.'/'.now()->format('Y/m').'/'.Str::uuid()->toString().($extension === '' ? '' : '.'.$extension);
        $disk = Message::mediaDisk();

        // O disco S3 tem `throw=false` e devolveria só `false`; o driver por baixo lança o motivo, que vai para o log.
        // O SDK do S3 rebobina o corpo para assinar o envio, e o stream do WAHA não volta atrás: passa por um temporário
        // (em memória até 5 MB, depois em disco).
        $temp = fopen('php://temp/maxmemory:5242880', 'w+b') ?: throw new RuntimeException('Não foi possível abrir o arquivo temporário da cópia.');
        stream_copy_to_stream(StreamWrapper::getResource($response->toPsrResponse()->getBody()), $temp);
        rewind($temp);

        try {
            $disk->getDriver()->writeStream($key, $temp);
        } catch (FilesystemException $e) {
            throw new RuntimeException('Não foi possível guardar a mídia da mensagem '.$message->id.' no disco: '.$e->getMessage(), 0, $e);
        } finally {
            fclose($temp);
        }

        $message->update(['media_path' => $key, 'media_size' => $disk->size($key)]);
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
