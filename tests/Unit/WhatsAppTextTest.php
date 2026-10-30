<?php

namespace Tests\Unit;

use App\Support\WhatsAppText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WhatsAppTextTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function casos(): array
    {
        return [
            'negrito' => ['Oi *tudo* bem', 'Oi <strong>tudo</strong> bem'],
            'italico' => ['Oi _tudo_ bem', 'Oi <em>tudo</em> bem'],
            'tachado' => ['Oi ~tudo~ bem', 'Oi <s>tudo</s> bem'],
            'monoespacado' => ['Use ```um codigo``` aqui', 'Use <code>um codigo</code> aqui'],
            'aninhado' => ['*_Jessé_ @ Dimetal:*', '<strong><em>Jessé</em> @ Dimetal:</strong>'],
            'asterisco solto' => ['2 * 3 = 6', '2 * 3 = 6'],
            'snake_case nao vira italico' => ['o campo nome_do_cliente e unico', 'o campo nome_do_cliente e unico'],
            'marca com espaco por dentro nao vale' => ['* nao *', '* nao *'],
            'varias linhas' => ["*a*\n\n_b_", "<strong>a</strong>\n\n<em>b</em>"],
            'dentro do codigo nada formata' => ['```*x*```', '<code>*x*</code>'],
            'html escapado' => ['<script>alert(1)</script> *ok*', '&lt;script&gt;alert(1)&lt;/script&gt; <strong>ok</strong>'],
            'atributo nao injeta' => ['*<img src=x onerror=alert(1)>*', '<strong>&lt;img src=x onerror=alert(1)&gt;</strong>'],
            'vazio' => ['', ''],
        ];
    }

    public function test_texto_longo_com_marcas_sem_par_nao_some(): void
    {
        $long = str_repeat('*a ', 1400);

        $this->assertStringContainsString('*a *a', html_entity_decode(strip_tags((string) WhatsAppText::html($long))));
        $this->assertNotSame('', (string) WhatsAppText::html($long));
    }

    public function test_byte_invalido_nao_apaga_a_mensagem(): void
    {
        $html = (string) WhatsAppText::html('ol'.chr(255).' *oi*');

        $this->assertStringContainsString('<strong>oi</strong>', $html);
        $this->assertStringContainsString('ol', $html);
    }

    #[DataProvider('casos')]
    public function test_formata_e_escapa(string $in, string $out): void
    {
        $this->assertSame($out, (string) WhatsAppText::html($in));
    }
}
