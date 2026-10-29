<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy
    |--------------------------------------------------------------------------
    |
    | `report-only` registra as violações no console do navegador sem bloquear nada (usar para validar uma política
    | nova); `enforce` bloqueia; `off` não envia o cabeçalho.
    |
    */

    'csp_mode' => env('CSP_MODE', 'report-only'),

    /*
    |--------------------------------------------------------------------------
    | Limites de requisição
    |--------------------------------------------------------------------------
    */

    'webhook_per_minute' => 300,

    'send_message_per_minute' => 30,

];
