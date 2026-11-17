<?php

namespace Tests\Unit\Update;

use App\Services\Update\Version;
use PHPUnit\Framework\TestCase;

class VersionTest extends TestCase
{
    public function test_ordem_semver(): void
    {
        foreach ([['0.1.0', '0.2.0'], ['0.2.0', '0.10.0'], ['1.0.0-teste.1', '1.0.0'], ['0.0.0-teste.1', '0.0.0-teste.2'], ['0.0.0-teste.2', '0.0.0-teste.10']] as [$menor, $maior]) {
            $this->assertTrue(Version::parse($maior)->greaterThan(Version::parse($menor)), "$maior > $menor");
            $this->assertFalse(Version::parse($menor)->greaterThan(Version::parse($maior)), "$menor !> $maior");
        }
        $this->assertFalse(Version::parse('1.0.0')->greaterThan(Version::parse('1.0.0+build')));
    }

    public function test_identificador_alfanumerico_compara_como_texto(): void
    {
        $this->assertTrue(Version::parse('1.0.0-9e1')->greaterThan(Version::parse('1.0.0-1e10')));
    }

    public function test_identificador_numerico_enorme_nao_estoura(): void
    {
        $this->assertTrue(Version::parse('1.0.0-100000000000000000000')->greaterThan(Version::parse('1.0.0-99999999999999999999')));
    }

    public function test_desenvolvimento_nao_e_release(): void
    {
        foreach (['sha-abc', 'edge', 'dev', ''] as $texto) {
            $this->assertNull(Version::parse($texto), $texto);
        }
    }

    public function test_prefixo_v(): void
    {
        $this->assertSame('0.2.0', (string) Version::parse('v0.2.0'));
        $this->assertTrue(Version::parse('0.0.0-teste.1')->isRelease());
    }
}
