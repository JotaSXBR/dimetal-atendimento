<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Presença do cliente (digitando / gravando)
    |--------------------------------------------------------------------------
    | Valores provisórios até a medição no beta (plano da fatia 2, tarefa 3).
    */

    // Quanto tempo a sessão fica online depois da mensagem de entrada (o `offline` sai no máximo(45, isto)).
    'window_seconds' => 60,

    // Quanto tempo vale o estado "digitando/gravando" sem um novo evento (rede de segurança se o `paused` nunca chegar).
    // Medido no beta em 04/11: o WhatsApp renova a cada ~12 s, então 25 s = 2x o intervalo.
    'state_ttl_seconds' => 25,

];
