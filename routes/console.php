<?php

use App\Services\Setup\DefaultSectors;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// `withoutOverlapping()` sem argumento segura o bloqueio por 24 h se o processo morrer no meio (um deploy mata o container):
// a tarefa pararia o dia inteiro. Com 10 minutos o bloqueio de uma rodada morta expira sozinho.
Schedule::command('app:close-inactive-tickets')->everyTenMinutes()->withoutOverlapping(10);
Schedule::command('app:expire-bot-sessions')->everyMinute()->withoutOverlapping(10);
Schedule::command('app:drain-outbox')->everyMinute()->withoutOverlapping(10);
Schedule::command('app:sync-google-contacts')->everyTenMinutes()->withoutOverlapping(10);
// O backup roda às 02:15 do horário de exibição (o `schedule` das demais tarefas é UTC); a checagem avisa os admins se passar de 26 h sem backup bom (ver config/backup.php).
Schedule::command('app:backup-database')->dailyAt(config()->string('backup.hour'))->timezone(config()->string('app.display_timezone'))->withoutOverlapping(60);
Schedule::command('app:check-backup-health')->hourly()->withoutOverlapping(10);
Schedule::command('app:purge-media')->dailyAt('03:30')->withoutOverlapping(10);
Schedule::command('app:purge-notifications')->dailyAt('03:45')->withoutOverlapping(10);
Schedule::command('app:recover-messages')->dailyAt('04:15')->withoutOverlapping(10);

// Setores padrão de uma instalação nova: o `migrate` roda no start do app (AUTORUN) e não há gancho de deploy próprio, então o
// agendador instala o padrão assim que sobe. Depois da primeira vez a marca em `settings` desliga o passo.
Schedule::call(fn () => DefaultSectors::install())->name('install-default-sectors')->everyMinute()->when(fn (): bool => ! DefaultSectors::isLoaded());

// Em segundo plano, para uma rodada lenta não atrasar as demais tarefas do minuto (elas rodam em sequência).
Schedule::command('app:check-sla')->everyMinute()->withoutOverlapping(5)->runInBackground();
