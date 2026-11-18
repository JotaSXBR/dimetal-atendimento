<?php

namespace Tests\Feature\Components;

use Tests\TestCase;

class PanelTest extends TestCase
{
    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($dom);
    }

    public function test_painel_com_titulo_subtitulo_e_acoes(): void
    {
        $xpath = $this->xpath((string) $this->blade('<x-panel title="Conexão" subtitle="Estado do canal"><x-slot:actions><button>Editar</button></x-slot:actions><p data-test="corpo">x</p></x-panel>'));

        $root = $xpath->query('//*[@data-panel]')->item(0);
        $this->assertNotNull($root);
        $this->assertSame('Conexão', trim($xpath->query('.//h2', $root)->item(0)?->textContent ?? ''));
        $this->assertSame('Estado do canal', trim($xpath->query('.//*[@data-panel-subtitle]', $root)->item(0)?->textContent ?? ''));
        $header = $xpath->query('./*[@data-panel-header]', $root)->item(0);
        $this->assertNotNull($header);
        $this->assertStringContainsString('border-b', $header->getAttribute('class'));
        $this->assertSame('Editar', trim($xpath->query('.//*[@data-panel-actions]//button', $header)->item(0)?->textContent ?? ''));
        $this->assertSame(0, $xpath->query('.//*[@data-test="corpo"]', $header)->length);
        $this->assertSame(1, $xpath->query('.//*[@data-test="corpo"]', $root)->length);
    }

    public function test_sem_subtitulo_nao_ha_subtitulo(): void
    {
        $xpath = $this->xpath((string) $this->blade('<x-panel title="Conexão">x</x-panel>'));

        $this->assertSame(0, $xpath->query('//*[@data-panel]//*[@data-panel-subtitle]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-panel]//*[@data-panel-actions]')->length);
    }
}
