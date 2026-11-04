<?php

namespace Tests\Unit;

use App\Enums\DeliveryStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeliveryStatusFromAckTest extends TestCase
{
    /** @return array<string, array{mixed, ?DeliveryStatus}> */
    public static function codes(): array
    {
        return [
            'falhou' => [-1, DeliveryStatus::FAILED],
            'enviado' => [1, DeliveryStatus::SENT],
            'entregue' => [2, DeliveryStatus::DELIVERED],
            'lido' => [3, DeliveryStatus::READ],
            'reproduzido' => [4, DeliveryStatus::READ],
            'zero ignorado' => [0, null],
            'texto numerico' => ['2', DeliveryStatus::DELIVERED],
            'nulo' => [null, null],
            'lixo' => ['x', null],
        ];
    }

    #[DataProvider('codes')]
    public function test_traduz_o_ack_do_waha(mixed $code, ?DeliveryStatus $expected): void
    {
        $this->assertSame($expected, DeliveryStatus::fromAck($code));
    }
}
