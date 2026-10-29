<?php

namespace App\Services\Inbox;

use App\Enums\MessageOrigin;
use App\Jobs\SendTicketMessageJob;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Envia um anexo do painel: confere o arquivo, guarda a cópia local (a espera pela reconexão e o reenvio precisam dela),
 * grava a linha e entrega à fila de saída. Cada arquivo é uma mensagem; a ordem entre eles é a da fila.
 */
class SendTicketMedia
{
    public function __construct(private readonly SendTicketMessage $messages) {}

    /**
     * @param  string  $caption  legenda (o texto digitado vai no primeiro arquivo)
     * @param  bool  $voice  gravação do microfone: vai como mensagem de voz
     *
     * @throws InvalidArgumentException arquivo recusado pelas regras (quem chama valida antes e mostra o erro)
     */
    public function __invoke(Ticket $ticket, ?User $author, UploadedFile $file, string $caption = '', bool $voice = false): Message
    {
        $info = AttachmentRules::inspect($file, $voice);

        if (is_string($info)) {
            throw new InvalidArgumentException($info);
        }

        $disk = Storage::disk('local');
        $path = $disk->putFileAs('outbound/'.now()->format('Y/m'), $file, Str::uuid()->toString().'.'.$info['ext']);

        if ($path === false) {
            throw new RuntimeException('Não foi possível guardar o arquivo no servidor.');
        }

        try {
            $message = $this->messages->record($ticket, $author, 'media', $caption, MessageOrigin::PAINEL, [
                'media_mime' => $info['mime'],
                'media_name' => $voice ? 'audio.'.$info['ext'] : $info['name'],
                'media_size' => $file->getSize(),
                'local_path' => $path,
            ]);
        } catch (Throwable $e) {
            // Sem linha não há dono para a cópia: ela não pode ficar órfã no disco.
            $disk->delete($path);

            throw $e;
        }

        SendTicketMessageJob::dispatch($message->id);

        return $message;
    }
}
