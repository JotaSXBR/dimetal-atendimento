<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup diário do banco
    |--------------------------------------------------------------------------
    |
    | `disk`: onde os dumps ficam (config/filesystems.php; S3/RustFS com BACKUP_BUCKET, senão pasta local).
    | `keep`: quantos arquivos de cada tipo ficam depois de um backup novo gravado.
    | `compress`: método do `pg_dump --compress` (cliente 18).
    | `hour`: horário do backup diário (antes da purga de mídia das 03:30).
    | `stale_hours`: sem backup bom há mais que isso, os admins são avisados.
    | `tmp_path`: onde o dump é gerado antes de subir (os testes usam uma pasta própria por teste: rodam em paralelo).
    |
    */

    'disk' => 'backups',

    'keep' => ['diario' => 7, 'semanal' => 2, 'manual' => 3, 'antes' => 2],

    'compress' => 'zstd:3',

    'hour' => '02:15',

    'stale_hours' => 26,

    'tmp_path' => storage_path('app/tmp'),

    /*
    | Restauração pela tela de Backup:
    | `confirm_word`: o que o admin digita para confirmar; `upload_max_mb`: tamanho máximo do arquivo enviado;
    | `keep_old_days`: por quantos dias o banco anterior (`<db>_antes_...`) fica guardado para desfazer;
    | `recover_hours`: até quantas horas para trás se recuperam mensagens do WhatsApp depois da troca;
    | `staging_timeout`: limite em segundos do `pg_restore` no banco novo (o job todo cabe nos 900 s do worker `history`);
    | `allow_upload`: aceita enviar um `.dump` do computador (`BACKUP_RESTORE_UPLOAD=false` desliga; o `pg_restore` executa o SQL do
    |   arquivo, então envie só arquivos de origem confiável);
    | `cutover_deadline`: segundos desde o início depois dos quais a troca não começa mais (sobra tempo para a troca e o `migrate`
    |   dentro dos 840 s do job, e o job nunca é morto pelo worker no meio da manutenção).
    */
    'restore' => [
        'confirm_word' => 'RESTAURAR',
        'upload_max_mb' => 100,
        'keep_old_days' => 7,
        'recover_hours' => 72,
        'staging_timeout' => 600,
        'allow_upload' => (bool) env('BACKUP_RESTORE_UPLOAD', true),
        'cutover_deadline' => 660,
    ],

];
