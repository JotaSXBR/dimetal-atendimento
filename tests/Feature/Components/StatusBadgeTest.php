<?php

namespace Tests\Feature\Components;

use App\Enums\ChannelStatus;
use App\Enums\TicketStatus;
use Tests\TestCase;

class StatusBadgeTest extends TestCase
{
    private function span(string $html): \DOMElement
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $node = (new \DOMXPath($dom))->query('//*[@data-status-tone]')->item(0);

        $this->assertInstanceOf(\DOMElement::class, $node, 'selo não renderizado');

        return $node;
    }

    /** O Blade embrulha a exceção do componente em `ViewException`: a original está no fim da cadeia de `getPrevious()`. */
    private function assertFalha(string $mensagem, string $blade): void
    {
        try {
            $this->blade($blade)->__toString();
        } catch (\Throwable $e) {
            $original = $e;
            while ($original->getPrevious() !== null) {
                $original = $original->getPrevious();
            }

            $this->assertInstanceOf(\InvalidArgumentException::class, $original);
            $this->assertStringContainsString($mensagem, $original->getMessage());

            return;
        }

        $this->fail('o componente deveria falhar: '.$mensagem);
    }

    public function test_renderiza_o_tom_e_o_texto_do_enum(): void
    {
        $span = $this->span((string) $this->blade('<x-status-badge :status="$status" />', ['status' => TicketStatus::AGUARDANDO]));

        $this->assertSame('warn', $span->getAttribute('data-status-tone'));
        $this->assertStringContainsString('text-warn', $span->getAttribute('class'));
        $this->assertStringContainsString('Aguardando', $span->textContent);
    }

    public function test_cada_tom_tem_as_suas_classes_de_token(): void
    {
        $esperado = [
            'neutral' => 'text-ink-2', 'bot' => 'text-bot', 'ok' => 'text-ok', 'warn' => 'text-warn', 'danger' => 'text-danger', 'accent' => 'text-accent-content',
        ];

        foreach ($esperado as $tone => $class) {
            $span = $this->span((string) $this->blade('<x-status-badge :tone="$tone">Texto</x-status-badge>', ['tone' => $tone]));

            $this->assertStringContainsString($class, $span->getAttribute('class'), $tone);
        }
    }

    public function test_slot_substitui_o_texto_e_tom_explicito_vale_sem_enum(): void
    {
        $span = $this->span((string) $this->blade('<x-status-badge tone="danger">SLA estourado</x-status-badge>'));

        $this->assertSame('danger', $span->getAttribute('data-status-tone'));
        $this->assertStringContainsString('SLA estourado', $span->textContent);
    }

    public function test_slot_vence_o_rotulo_do_enum(): void
    {
        $span = $this->span((string) $this->blade('<x-status-badge :status="$status">Sessão removida</x-status-badge>', ['status' => ChannelStatus::WORKING]));

        $this->assertSame('ok', $span->getAttribute('data-status-tone'));
        $this->assertStringContainsString('Sessão removida', $span->textContent);
        $this->assertStringNotContainsString('Conectado', $span->textContent);
    }

    public function test_tom_desconhecido_falha_alto(): void
    {
        $this->assertFalha('x-status-badge: tom desconhecido "rosa"', '<x-status-badge tone="rosa">Texto</x-status-badge>');
    }

    public function test_sem_estado_nem_tom_falha_alto(): void
    {
        $this->assertFalha('x-status-badge: informe `status` ou `tone`', '<x-status-badge>Texto</x-status-badge>');
    }

    public function test_ponto_e_decorativo_e_o_texto_sempre_existe(): void
    {
        $span = $this->span((string) $this->blade('<x-status-badge :status="$status" />', ['status' => TicketStatus::ENCERRADO]));
        $dom = new \DOMXPath($span->ownerDocument);

        $this->assertSame('true', $dom->query('.//*[@aria-hidden]', $span)->item(0)?->getAttribute('aria-hidden'));
        $this->assertNotSame('', trim($span->textContent));
    }

    public function test_classe_do_chamador_se_mistura(): void
    {
        $span = $this->span((string) $this->blade('<x-status-badge tone="ok" class="ms-2">Ok</x-status-badge>'));

        $this->assertStringContainsString('ms-2', $span->getAttribute('class'));
    }
}
