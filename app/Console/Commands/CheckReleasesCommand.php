<?php

namespace App\Console\Commands;

use App\Services\Update\ReleaseFeed;
use App\Settings\UpdateSettings;
use Illuminate\Console\Command;

class CheckReleasesCommand extends Command
{
    protected $signature = 'app:check-releases';

    protected $description = 'Consulta as Releases do GitHub e avisa os admins de versão nova';

    public function handle(ReleaseFeed $feed): int
    {
        $feed->refresh();
        $error = UpdateSettings::lastCheck()['error'] ?? null;

        $error === null ? $this->info('Consulta às Releases concluída.') : $this->warn($error);

        return self::SUCCESS;
    }
}
