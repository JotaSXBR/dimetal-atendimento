<?php

namespace Tests\Unit\Bot;

use App\Services\Bot\Answers\Answers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnswersTest extends TestCase
{
    /** @return array<string, array{string, string, string|null}> */
    public static function cases(): array
    {
        return [
            'cnpj com máscara' => ['cnpj', '11.222.333/0001-81', '11.222.333/0001-81'],
            'cnpj só números' => ['cnpj', '11222333000181', '11.222.333/0001-81'],
            'cnpj alfanumérico em minúsculas' => ['cnpj', '12abc34501de35', '12.ABC.345/01DE-35'],
            'cnpj dígito errado' => ['cnpj', '11222333000180', null],
            'cnpj só zeros' => ['cnpj', '00000000000000', null],
            'cnpj 13 posições' => ['cnpj', '1122233300018', null],
            'cnpj 15 posições' => ['cnpj', '112223330001811', null],
            'cpf válido' => ['cpf', '11122233396', '111.222.333-96'],
            'cpf com máscara' => ['cpf', '111.222.333-96', '111.222.333-96'],
            'cpf repetido' => ['cpf', '11111111111', null],
            'cpf dígito errado' => ['cpf', '11122233395', null],
            'cep sem hífen' => ['cep', '01310100', '01310-100'],
            'cep com hífen' => ['cep', '01310-100', '01310-100'],
            'cep curto' => ['cep', '1310100', null],
            'telefone celular' => ['telefone', '11988887777', '(11) 98888-7777'],
            'telefone com +55 e máscara' => ['telefone', '+55 (11) 98888-7777', '(11) 98888-7777'],
            'telefone colado' => ['telefone', '(11)98888-7777', '(11) 98888-7777'],
            'telefone fixo' => ['telefone', '1133334444', '(11) 3333-4444'],
            'telefone sem ddd' => ['telefone', '988887777', null],
            'data válida' => ['data', '05/11/2026', '05/11/2026'],
            'data sem zero' => ['data', '5/11/2026', '05/11/2026'],
            'data impossível' => ['data', '31/02/2026', null],
            'bissexto inexistente' => ['data', '29/02/2025', null],
            'bissexto existente' => ['data', '29/02/2024', '29/02/2024'],
            'data iso' => ['data', '2026-11-05', null],
            'cnpj com assinatura do cliente na linha de cima' => ['cnpj', "*#Jessé:*\n11.222.333/0001-81", '11.222.333/0001-81'],
            'cnpj dentro de uma frase' => ['cnpj', 'meu cnpj é 11.222.333/0001-81, obrigado', '11.222.333/0001-81'],
            'cnpj alfanumérico dentro de uma frase' => ['cnpj', 'segue: 12.abc.345/01de-35', '12.ABC.345/01DE-35'],
            'cpf com outros números na frase' => ['cpf', 'apto 12, cpf 11122233396', '111.222.333-96'],
            'cep com número de casa na frase' => ['cep', 'rua x 123 cep 01310100', '01310-100'],
            'duas linhas valem a primeira que vale' => ['cnpj', "11.222.333/0001-81\n11.222.333/0001-80", '11.222.333/0001-81'],
            'frase sem valor válido' => ['cnpj', 'não sei o cnpj agora', null],
            'tipo desconhecido devolve o texto' => ['qualquer', '  oi  ', 'oi'],
        ];
    }

    #[DataProvider('cases')]
    public function test_parse(string $kind, string $text, ?string $expected): void
    {
        $this->assertSame($expected, Answers::parse($kind, $text));
    }
}
