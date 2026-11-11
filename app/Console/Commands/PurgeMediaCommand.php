<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Message;
use App\Models\TicketEvent;
use Illuminate\Console\Command;

class PurgeMediaCommand extends Command
{
    /** Retenção dos arquivos: o texto e os eventos ficam para sempre, o arquivo vai embora com 90 dias. */
    public const RETENTION_DAYS = 90;

    protected $signature = 'app:purge-media';

    protected $description = 'Apaga do disco de mídia os anexos (recebidos e enviados) com mais de 90 dias, registra o evento media_purged e zera a URL da foto dos contatos sem atualização há 90 dias';

    /**
     * Idempotente: a linha que já perdeu a cópia (`media_path` nulo) não é tocada de novo. Cópia que já sumiu do disco
     * também é dada como expirada, para a tela nunca prometer um arquivo que não existe.
     */
    public function handle(): int
    {
        $disk = Message::mediaDisk();
        $purged = 0;

        Message::query()
            ->whereNotNull('media_path')
            ->where('created_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->each(function (Message $message) use ($disk, &$purged): void {
                if ($message->media_path !== null) {
                    $disk->delete($message->media_path);
                }

                $message->update(['media_path' => null, 'media_purged_at' => now()]);

                TicketEvent::create([
                    'ticket_id' => $message->ticket_id,
                    'type' => 'media_purged',
                    'payload' => ['message_id' => $message->id],
                ]);

                $purged++;
            });

        $this->info("Anexos expirados: {$purged}");

        // A foto do contato nunca é guardada: o que expira é a URL do CDN. Contato que escreve renova a checagem a cada 24 h.
        $photos = Contact::query()
            ->whereNotNull('avatar_checked_at')
            ->where('avatar_checked_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->update(['avatar_url' => null, 'avatar_checked_at' => null]);

        $this->info("Fotos de contato expiradas: {$photos}");

        return self::SUCCESS;
    }
}
