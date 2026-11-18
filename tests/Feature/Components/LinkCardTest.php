<?php

namespace Tests\Feature\Components;

use Tests\TestCase;

class LinkCardTest extends TestCase
{
    private function root(string $html): \DOMElement
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $root = (new \DOMXPath($dom))->query('//*[@data-link-card]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $root);

        return $root;
    }

    private function title(\DOMElement $root): string
    {
        return trim((string) (new \DOMXPath($root->ownerDocument))->query('.//*[@data-link-card-title]', $root)->item(0)?->textContent);
    }

    private function hasChevron(\DOMElement $root): bool
    {
        return (new \DOMXPath($root->ownerDocument))->query('.//*[@data-link-card-chevron]', $root)->length > 0;
    }

    public function test_com_href_e_link_com_seta(): void
    {
        $root = $this->root($this->blade('<x-link-card href="/x" title="2026-000142">texto</x-link-card>')->__toString());

        $this->assertSame('a', $root->tagName);
        $this->assertSame('/x', $root->getAttribute('href'));
        $this->assertTrue($root->hasAttribute('wire:navigate'));
        $this->assertTrue($this->hasChevron($root));
        $this->assertSame('2026-000142', $this->title($root));
        $this->assertStringContainsString('texto', $root->textContent);
    }

    public function test_com_wire_click_e_botao_com_seta(): void
    {
        $root = $this->root($this->blade('<x-link-card wire:click="foo" title="2026-000142">texto</x-link-card>')->__toString());

        $this->assertSame('button', $root->tagName);
        $this->assertSame('button', $root->getAttribute('type'));
        $this->assertSame('foo', $root->getAttribute('wire:click'));
        $this->assertTrue($this->hasChevron($root));
    }

    public function test_sem_destino_e_estatico_sem_seta(): void
    {
        $root = $this->root($this->blade('<x-link-card title="2026-000142">texto</x-link-card>')->__toString());

        $this->assertSame('div', $root->tagName);
        $this->assertFalse($root->hasAttribute('wire:navigate'));
        $this->assertFalse($this->hasChevron($root));
        $this->assertSame('2026-000142', $this->title($root));
    }
}
