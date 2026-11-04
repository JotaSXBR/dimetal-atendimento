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

    /** Presença do cliente (`presence.update`) tem teto próprio: chega em rajada e não pode gastar o das mensagens. */
    'presence_per_minute' => 600,

    'send_message_per_minute' => 30,

    /** Reagir, editar e apagar mensagem pelo painel: por usuário, junto (cada ação chama o WhatsApp). */
    'message_action_per_minute' => 30,

];
