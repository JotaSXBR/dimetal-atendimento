<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// `withoutOverlapping()` sem argumento segura o bloqueio por 24 h se o processo morrer no meio (um deploy mata o container):
// a tarefa pararia o dia inteiro. Com 10 minutos o bloqueio de uma rodada morta expira sozinho.
Schedule::command('app:close-inactive-tickets')->everyTenMinutes()->withoutOverlapping(10);
Schedule::command('app:drain-outbox')->everyMinute()->withoutOverlapping(10);
Schedule::command('app:sync-google-contacts')->everyTenMinutes()->withoutOverlapping(10);
Schedule::command('app:purge-media')->dailyAt('03:30')->withoutOverlapping(10);
Schedule::command('app:purge-notifications')->dailyAt('03:45')->withoutOverlapping(10);

// Em segundo plano, para uma rodada lenta não atrasar as demais tarefas do minuto (elas rodam em sequência).
Schedule::command('app:check-sla')->everyMinute()->withoutOverlapping(5)->runInBackground();
