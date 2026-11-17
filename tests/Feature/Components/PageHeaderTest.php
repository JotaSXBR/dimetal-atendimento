<?php

namespace Tests\Feature\Components;

use Tests\TestCase;

class PageHeaderTest extends TestCase
{
    public function test_cabecalho_tem_titulo_subtitulo_e_acoes_marcados(): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$this->blade('<x-page-header title="T" subtitle="S"><button>Ação</button></x-page-header>'), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $root = $xpath->query('//*[@data-page-header]')->item(0);
        $this->assertNotNull($root);
        $this->assertStringContainsString('border-line', $root->getAttribute('class'));
        $this->assertSame('T', trim($xpath->query('.//h1', $root)->item(0)?->textContent ?? ''));
        $this->assertStringContainsString('S', $xpath->query('.//*[@data-flux-subheading]', $root)->item(0)?->textContent ?? '');
        $this->assertSame('Ação', trim($xpath->query('.//*[@data-page-header-actions]//button', $root)->item(0)?->textContent ?? ''));
    }
}
