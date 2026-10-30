<?php

namespace App\Console\Commands;

use App\Services\Inbox\SlaClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckSlaCommand extends Command
{
    /** A batida da rotina: a tela de Relatórios avisa quando ela para de aparecer. */
    public const HEARTBEAT = 'sla:last-run';

    protected $signature = 'app:check-sla';

    protected $description = 'Calcula os vencimentos de SLA, marca o aviso e o estouro, cria o evento sla_breached e avisa a tela';

    /** Roda de minuto em minuto; cada rodada trata no máximo {@see SlaClock::BATCH} tickets por passo. */
    public function handle(SlaClock $clock): int
    {
        $result = $clock();

        Cache::put(self::HEARTBEAT, now(), 600);

        $this->info(sprintf('SLA: %d adotados, %d calculados, %d avisos, %d estourados.', $result['adopted'], $result['scheduled'], $result['warned'], $result['breached']));

        return self::SUCCESS;
    }
}
