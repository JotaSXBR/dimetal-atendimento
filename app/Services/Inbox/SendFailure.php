<?php

namespace App\Services\Inbox;

use App\Services\Waha\WahaRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Por que um envio ao WAHA não deu certo, e o que isso permite fazer: esperar, reenviar ou deixar quieto.
 *
 * A pergunta que decide tudo é "a mensagem pode ter saído?". Repetir um envio que saiu duplica a mensagem para o
 * cliente (WAHA #1225), e insistir depois de um limite do WhatsApp (463/475) piora a conta; já o que certamente não
 * saiu (sessão fora do ar, conexão recusada) espera a reconexão. O desconhecido cai em "confirmar": perguntar custa um
 * toque, duplicar custa a confiança do cliente.
 */
final class SendFailure
{
    /** Certamente não saiu: a sessão está fora de `WORKING` ou o WAHA nem atendeu. A mensagem espera na fila. */
    public const SESSION_DOWN = 'session_down';

    /** O WhatsApp limitou a conta (463/475): nada de reenviar nem de reiniciar a sessão. */
    public const RATE_LIMIT = 'rate_limit';

    /** Sem resposta clara (timeout, conexão resetada, gateway): pode ter saído. */
    public const UNKNOWN = 'unknown';

    /** O WAHA recusou com um motivo (número sem WhatsApp, dado inválido). */
    public const REFUSED = 'refused';

    /** Esperou 24 h pela conexão e não foi enviada. */
    public const EXPIRED = 'expired';

    public static function kind(Throwable $error): string
    {
        $text = $error->getMessage();

        if ($error instanceof ConnectionException) {
            // 6 = DNS, 7 = recusada: o pedido nem chegou. 28 (timeout), 52 e 56 não dizem se chegou.
            return preg_match('/cURL error (6|7)\b|Connection refused|Could not resolve/i', $text) === 1 ? self::SESSION_DOWN : self::UNKNOWN;
        }

        if (! $error instanceof WahaRequestException) {
            return self::UNKNOWN;
        }

        return match (true) {
            preg_match('/\b(463|475)\b/', $text) === 1 => self::RATE_LIMIT,
            $error->statusCode === 422 && preg_match('/Session status|not as expected/i', $text) === 1 => self::SESSION_DOWN,
            in_array($error->statusCode, [502, 503, 504], true) => self::UNKNOWN,
            default => self::REFUSED,
        };
    }

    public static function reason(string $kind, Throwable $error): string
    {
        return match ($kind) {
            self::RATE_LIMIT => 'Limite do WhatsApp (463/475): aguarde antes de enviar de novo; não reinicie a sessão.',
            self::UNKNOWN => 'Sem resposta do WhatsApp: a mensagem pode ter sido enviada. Confira no celular do cliente antes de reenviar.',
            self::EXPIRED => 'Não foi enviada: o WhatsApp ficou desconectado por mais de 24 h.',
            default => Str::limit($error->getMessage(), 250),
        };
    }

    /**
     * O que o botão "Reenviar" faz com uma falha: `safe` envia direto, `confirm` pede confirmação, `none` não aparece.
     * Uma regra só, usada pela tela e pelo servidor, para a tela nunca oferecer o que o servidor recusa.
     */
    public static function resend(?string $code): string
    {
        return match ($code) {
            self::EXPIRED => 'safe',
            self::RATE_LIMIT => 'none',
            default => 'confirm',
        };
    }
}
