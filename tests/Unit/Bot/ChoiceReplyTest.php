<?php

namespace Tests\Unit\Bot;

use App\Services\Bot\ChoiceReply;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChoiceReplyTest extends TestCase
{
    /** @return array<string, array{string, int}> */
    public static function chosen(): array
    {
        return [
            'so o numero' => ['1', 1],
            'com espacos' => [' 2 ', 2],
            'assinatura antes' => ["*#Jessé:*\n1", 1],
            'assinatura depois' => ["1\n*#Jessé:*", 1],
            'crlf e linhas vazias' => ["*#Jessé:*\r\n\r\n1\r\n", 1],
            'telefone na assinatura' => ["Fulano\nTel 3333-4444\n1", 1],
            'mesmo numero repetido' => ["1\n1", 1],
            'zero e numero (opcao inexistente)' => ['0', 0],
        ];
    }

    #[DataProvider('chosen')]
    public function test_devolve_o_numero_escolhido(string $text, int $expected): void
    {
        $this->assertSame($expected, ChoiceReply::number($text));
    }

    /** @return array<string, array{string}> */
    public static function notChosen(): array
    {
        return [
            'vazio' => [''],
            'palavra' => ['oi'],
            'so assinatura' => ["*#Jessé:*\nopa"],
            'valores diferentes' => ["1\n2"],
            'quatro digitos' => ['1234'],
            'numeros na mesma linha' => ['1 2'],
            'endereco' => ['Rua 10, 200'],
            'cep na frase' => ["Meu CEP 14010-000\nobrigado"],
            'telefone sozinho' => ['3333-4444'],
        ];
    }

    #[DataProvider('notChosen')]
    public function test_sem_escolha_clara_devolve_nulo(string $text): void
    {
        $this->assertNull(ChoiceReply::number($text));
    }

    /** @return array<string, array{string, bool}> */
    public static function exits(): array
    {
        return [
            'so a saida' => ['#sair', true],
            'maiusculas e espacos' => ['  #SAIR ', true],
            'com assinatura' => ["*#Jessé:*\n#sair", true],
            'com numero' => ["#sair\n1", true],
            'vazio' => ['', false],
            'sem cerquilha' => ['sair', false],
            'colado a outras palavras' => ['#sair agora', false],
            'no meio da frase' => ['quero #sair do plano', false],
            'so um numero' => ['1', false],
        ];
    }

    #[DataProvider('exits')]
    public function test_reconhece_o_pedido_de_saida(string $text, bool $expected): void
    {
        $this->assertSame($expected, ChoiceReply::wantsOut($text));
    }
}
