<?php

namespace Tests\Feature\Inbox;

use App\Services\Inbox\SendFailure;
use App\Services\Waha\WahaRequestException;
use Illuminate\Http\Client\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SendFailureTest extends TestCase
{
    /** @return array<string, array{Throwable, string}> */
    public static function cases(): array
    {
        return [
            'sessao fora de WORKING (422)' => [new WahaRequestException(422, 'WAHA respondeu 422: Session status is not as expected. Try again later or restart the session'), SendFailure::SESSION_DOWN],
            'conexao recusada' => [new ConnectionException('cURL error 7: Failed to connect to waha-beta port 3000: Connection refused'), SendFailure::SESSION_DOWN],
            'dns' => [new ConnectionException('cURL error 6: Could not resolve host: waha-beta'), SendFailure::SESSION_DOWN],
            'timeout pode ter saido' => [new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received'), SendFailure::UNKNOWN],
            'conexao resetada' => [new ConnectionException('cURL error 56: Recv failure: Connection reset by peer'), SendFailure::UNKNOWN],
            'resposta vazia' => [new ConnectionException('cURL error 52: Empty reply from server'), SendFailure::UNKNOWN],
            'gateway' => [new WahaRequestException(502, 'WAHA respondeu 502: Bad Gateway'), SendFailure::UNKNOWN],
            '463' => [new WahaRequestException(500, 'WAHA respondeu 500: 2 UNKNOWN: error 463 tc-token'), SendFailure::RATE_LIMIT],
            '475' => [new WahaRequestException(500, 'WAHA respondeu 500: error 475'), SendFailure::RATE_LIMIT],
            'recusa com motivo' => [new WahaRequestException(500, 'WAHA respondeu 500: recipient is not on WhatsApp'), SendFailure::REFUSED],
            '422 que nao e de sessao' => [new WahaRequestException(422, 'WAHA respondeu 422: chatId must be a string'), SendFailure::REFUSED],
            'erro qualquer' => [new RuntimeException('boom'), SendFailure::UNKNOWN],
        ];
    }

    #[DataProvider('cases')]
    public function test_classifica_a_falha_pelo_que_se_sabe_sobre_ter_saido_ou_nao(\Throwable $error, string $expected): void
    {
        $this->assertSame($expected, SendFailure::kind($error));
    }

    public function test_motivos_para_o_atendente(): void
    {
        $this->assertStringContainsString('pode ter sido enviada', SendFailure::reason(SendFailure::UNKNOWN, new RuntimeException('x')));
        $this->assertStringContainsString('não reinicie a sessão', SendFailure::reason(SendFailure::RATE_LIMIT, new RuntimeException('x')));
        $this->assertSame('recipient is not on WhatsApp', SendFailure::reason(SendFailure::REFUSED, new WahaRequestException(500, 'recipient is not on WhatsApp')));
        $this->assertStringContainsString('24 h', SendFailure::reason(SendFailure::EXPIRED, new RuntimeException('x')));
    }

    public function test_so_nao_confirma_antes_de_reenviar_o_que_certamente_nao_saiu_ou_expirou(): void
    {
        $this->assertSame('safe', SendFailure::resend(SendFailure::EXPIRED));
        $this->assertSame('confirm', SendFailure::resend(SendFailure::UNKNOWN));
        $this->assertSame('confirm', SendFailure::resend(SendFailure::REFUSED));
        $this->assertSame('none', SendFailure::resend(SendFailure::RATE_LIMIT));
        $this->assertSame('confirm', SendFailure::resend(null));
    }
}
