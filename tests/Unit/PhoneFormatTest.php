<?php

namespace Tests\Unit;

use App\Support\PhoneFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneFormatTest extends TestCase
{
    /** @return array<string, array{string|null, string|null, string}> */
    public static function exibicoes(): array
    {
        return [
            'celular' => ['5516997524950@c.us', null, '(16) 99752-4950'],
            'fixo' => ['551633334444@c.us', null, '(16) 3333-4444'],
            'outro pais' => ['14155550100@c.us', null, '+14155550100'],
            'so lid' => [null, '16449842241553@lid', 'Número oculto pelo WhatsApp'],
            'nada' => [null, null, 'Número oculto pelo WhatsApp'],
        ];
    }

    #[DataProvider('exibicoes')]
    public function test_display(?string $waId, ?string $lid, string $esperado): void
    {
        $this->assertSame($esperado, PhoneFormat::display($waId, $lid));
    }

    /** @return array<string, array{string, string|null}> */
    public static function entradas(): array
    {
        return [
            'com mascara' => ['(16) 99752-4950', '5516997524950@c.us'],
            'com ddi' => ['+55 16 99752-4950', '5516997524950@c.us'],
            'so digitos' => ['16997524950', '5516997524950@c.us'],
            'curto' => ['12', null],
            'letras' => ['abc', null],
            'longo demais' => ['1234567890123456', null],
        ];
    }

    #[DataProvider('entradas')]
    public function test_to_wa_id(string $entrada, ?string $esperado): void
    {
        $this->assertSame($esperado, PhoneFormat::toWaId($entrada));
    }
}
