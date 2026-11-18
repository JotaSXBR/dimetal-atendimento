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

    /** Com base própria o bloco do título encolhe e quebra o subtítulo: as ações só descem quando faltam ~20rem para o texto. */
    public function test_bloco_do_titulo_encolhe_antes_de_derrubar_as_acoes(): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$this->blade('<x-page-header title="T" subtitle="S"><button>Ação</button></x-page-header>'), LIBXML_NOERROR | LIBXML_NOWARNING);

        $titles = (new \DOMXPath($dom))->query('//*[@data-page-header]//h1/ancestor::div[1]')->item(0);
        $this->assertNotNull($titles);
        $classes = explode(' ', $titles->getAttribute('class'));
        foreach (['min-w-0', 'grow', 'basis-80'] as $class) {
            $this->assertContains($class, $classes);
        }
    }

    public function test_abas_ficam_dentro_do_cabecalho(): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$this->blade('<x-page-header title="T"><x-slot:tabs><nav aria-label="Abas">x</nav></x-slot:tabs></x-page-header>'), LIBXML_NOERROR | LIBXML_NOWARNING);

        $this->assertSame(1, (new \DOMXPath($dom))->query('//*[@data-page-header]//nav[@aria-label="Abas"]')->length);
    }
}
