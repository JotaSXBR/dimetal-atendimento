<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\TicketEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeMediaCommand extends Command
{
    /** Retenção dos arquivos: o texto e os eventos ficam para sempre, o arquivo vai embora com 90 dias. */
    public const RETENTION_DAYS = 90;

    protected $signature = 'app:purge-media';

    protected $description = 'Apaga do disco as cópias locais de anexos enviados há mais de 90 dias e registra o evento media_purged';

    /**
     * Idempotente: a linha que já perdeu a cópia (`local_path` nulo) não é tocada de novo. Cópia que já sumiu do disco
     * também é dada como expirada, para a tela nunca prometer um arquivo que não existe.
     */
    public function handle(): int
    {
        $disk = Storage::disk('local');
        $purged = 0;

        Message::query()
            ->whereNotNull('local_path')
            ->where('created_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->each(function (Message $message) use ($disk, &$purged): void {
                if ($message->local_path !== null) {
                    $disk->delete($message->local_path);
                }

                $message->update(['local_path' => null, 'media_purged_at' => now()]);

                TicketEvent::create([
                    'ticket_id' => $message->ticket_id,
                    'type' => 'media_purged',
                    'payload' => ['message_id' => $message->id],
                ]);

                $purged++;
            });

        $this->info("Anexos expirados: {$purged}");

        return self::SUCCESS;
    }
}
