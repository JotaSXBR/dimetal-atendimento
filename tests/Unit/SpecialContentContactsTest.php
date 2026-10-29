<?php

namespace Tests\Unit;

use App\Services\Inbox\SpecialContent;
use PHPUnit\Framework\TestCase;

class SpecialContentContactsTest extends TestCase
{
    public function test_le_nome_iniciais_e_telefones_de_cada_linha(): void
    {
        $contacts = SpecialContent::contacts("Contato: Maria Souza (+55 16 99999-0001)\nContato: Beto (+55 11 90000-0002, +55 11 3000-0000)");

        $this->assertSame([
            ['name' => 'Maria Souza', 'initials' => 'MS', 'phones' => ['+55 16 99999-0001']],
            ['name' => 'Beto', 'initials' => 'B', 'phones' => ['+55 11 90000-0002', '+55 11 3000-0000']],
        ], $contacts);
    }

    public function test_parenteses_no_nome_nao_viram_telefone(): void
    {
        $this->assertSame(
            [['name' => 'Maria (Vendas)', 'initials' => 'MV', 'phones' => ['+55 16 99999-0001']]],
            SpecialContent::contacts('Contato: Maria (Vendas) (+55 16 99999-0001)'),
        );
        $this->assertSame(
            [['name' => 'Maria (Vendas)', 'initials' => 'MV', 'phones' => []]],
            SpecialContent::contacts('Contato: Maria (Vendas)'),
        );
    }

    public function test_linha_que_nao_e_de_contato_e_ignorada(): void
    {
        $this->assertSame([], SpecialContent::contacts("texto qualquer\n"));
    }
}
