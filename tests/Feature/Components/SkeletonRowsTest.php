<?php

namespace Tests\Feature\Components;

use Tests\TestCase;

class SkeletonRowsTest extends TestCase
{
    private function xpath(string $blade): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$this->blade($blade), LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($dom);
    }

    public function test_renderiza_n_linhas_escondidas_do_leitor_de_tela_com_animacao(): void
    {
        $xpath = $this->xpath('<x-skeleton-rows :rows="4" />');

        $root = $xpath->query('//*[@data-skeleton]')->item(0);
        $this->assertNotNull($root);
        $this->assertSame('true', $root->getAttribute('aria-hidden'));
        $this->assertSame(4, $xpath->query('.//*[@data-skeleton-row]', $root)->length);
        $this->assertGreaterThan(0, $xpath->query('.//*[contains(@class, "animate-pulse")]', $root)->length);
    }

    public function test_atributos_do_chamador_chegam_a_raiz(): void
    {
        $xpath = $this->xpath('<x-skeleton-rows wire:loading.delay wire:target="q" class="mt-2" />');

        $root = $xpath->query('//*[@data-skeleton]')->item(0);
        $this->assertTrue($root->hasAttribute('wire:loading.delay'));
        $this->assertSame('q', $root->getAttribute('wire:target'));
        $this->assertStringContainsString('mt-2', $root->getAttribute('class'));
    }

    public function test_padrao_tem_cinco_linhas(): void
    {
        $this->assertSame(5, $this->xpath('<x-skeleton-rows />')->query('//*[@data-skeleton-row]')->length);
    }
}
