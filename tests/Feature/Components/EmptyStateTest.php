<?php

namespace Tests\Feature\Components;

use Tests\TestCase;

class EmptyStateTest extends TestCase
{
    private function xpath(string $blade): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$this->blade($blade), LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($dom);
    }

    public function test_mostra_icone_titulo_e_descricao(): void
    {
        $xpath = $this->xpath('<x-empty-state icon="users" title="Nenhum contato" description="Cadastre o primeiro." />');

        $root = $xpath->query('//*[@data-empty-state]')->item(0);
        $this->assertNotNull($root);
        $this->assertSame(1, $xpath->query('.//svg', $root)->length, 'ícone');
        $this->assertStringContainsString('Nenhum contato', $root->textContent);
        $this->assertStringContainsString('Cadastre o primeiro.', $root->textContent);
    }

    public function test_descricao_e_opcional(): void
    {
        $xpath = $this->xpath('<x-empty-state title="Nada por aqui" />');

        $this->assertSame(1, $xpath->query('//*[@data-empty-state]//p')->length);
    }

    public function test_acao_so_aparece_quando_ha_slot(): void
    {
        $com = $this->xpath('<x-empty-state title="Nada"><x-slot:action><a href="#">Criar</a></x-slot:action></x-empty-state>');
        $sem = $this->xpath('<x-empty-state title="Nada" />');

        $this->assertStringContainsString('Criar', $com->query('//*[@data-empty-state-action]')->item(0)?->textContent ?? '');
        $this->assertSame(0, $sem->query('//*[@data-empty-state-action]')->length);
    }

    public function test_compacto_ocupa_menos_altura_e_classe_do_chamador_se_mistura(): void
    {
        $normal = $this->xpath('<x-empty-state title="Nada" />')->query('//*[@data-empty-state]')->item(0);
        $compact = $this->xpath('<x-empty-state title="Nada" compact class="ms-2" />')->query('//*[@data-empty-state]')->item(0);

        $this->assertStringContainsString('py-10', $normal->getAttribute('class'));
        $this->assertStringContainsString('py-4', $compact->getAttribute('class'));
        $this->assertStringContainsString('ms-2', $compact->getAttribute('class'));
    }
}
