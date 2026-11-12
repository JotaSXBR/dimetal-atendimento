<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy
    |--------------------------------------------------------------------------
    |
    | `enforce` bloqueia (padrão, validado no beta em 12/11/2026); `report-only` só registra as violações no console do
    | navegador (usar para testar uma política nova, ou em desenvolvimento com o Vite em modo `npm run dev`, que serve
    | scripts de outra origem); `off` não envia o cabeçalho. Voltar atrás em produção: `CSP_MODE=report-only` e reiniciar.
    |
    */

    'csp_mode' => env('CSP_MODE', 'enforce'),

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
