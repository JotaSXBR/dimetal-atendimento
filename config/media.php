<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disco da mídia
    |--------------------------------------------------------------------------
    |
    | Onde ficam os anexos recebidos e enviados (`messages.media_path` é a chave neste disco). `local` em desenvolvimento e
    | testes; `s3` (RustFS) no beta, com o disco `s3` de config/filesystems.php.
    |
    */

    'disk' => env('MEDIA_DISK', 'local'),

];
