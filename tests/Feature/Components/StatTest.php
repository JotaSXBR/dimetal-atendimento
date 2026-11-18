<?php

namespace Tests\Feature\Components;

use Tests\TestCase;

class StatTest extends TestCase
{
    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?><dl>'.$html.'</dl>', LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($dom);
    }

    public function test_stat_mostra_rotulo_valor_e_nota(): void
    {
        $xpath = $this->xpath((string) $this->blade('<x-stat label="SLA cumprido" value="96,4%" note="no período" />'));

        $this->assertSame(1, $xpath->query('//*[@data-stat]')->length);
        $this->assertSame('SLA cumprido', trim($xpath->query('//*[@data-stat]/dt')->item(0)?->textContent ?? ''));
        $this->assertSame('96,4%', trim($xpath->query('//*[@data-stat]//*[@data-stat-value]')->item(0)?->textContent ?? ''));
        $this->assertSame('no período', trim($xpath->query('//*[@data-stat]//*[@data-stat-note]')->item(0)?->textContent ?? ''));

        $semNota = $this->xpath((string) $this->blade('<x-stat label="Tickets abertos" :value="12" />'));
        $this->assertSame('12', trim($semNota->query('//*[@data-stat-value]')->item(0)?->textContent ?? ''));
        $this->assertSame(0, $semNota->query('//*[@data-stat-note]')->length);
    }

    public function test_tom_desconhecido_falha(): void
    {
        try {
            $this->blade('<x-stat label="Vencidos" :value="3" tone="warn" />')->__toString();
        } catch (\Throwable $e) {
            // O Blade embrulha a exceção do componente em `ViewException`: a original está no fim da cadeia.
            while ($e->getPrevious() !== null) {
                $e = $e->getPrevious();
            }
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertStringContainsString('warn', $e->getMessage());

            return;
        }

        $this->fail('x-stat deveria recusar o tom "warn"');
    }

    public function test_tom_danger_pinta_o_valor(): void
    {
        $value = $this->xpath((string) $this->blade('<x-stat label="Vencidos" :value="3" tone="danger" />'))->query('//*[@data-stat-value]')->item(0);

        $this->assertInstanceOf(\DOMElement::class, $value);
        $this->assertContains('text-danger', explode(' ', $value->getAttribute('class')));

        $neutro = $this->xpath((string) $this->blade('<x-stat label="Vencidos" :value="0" />'))->query('//*[@data-stat-value]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $neutro);
        $this->assertNotContains('text-danger', explode(' ', $neutro->getAttribute('class')));
    }
}
