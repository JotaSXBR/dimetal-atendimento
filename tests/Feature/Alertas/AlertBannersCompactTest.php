<?php

namespace Tests\Feature\Alertas;

use App\Enums\ChannelStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Os avisos do topo ocupam uma linha: em 1280x720 a altura é o recurso escasso (spec 4a). */
class AlertBannersCompactTest extends TestCase
{
    use RefreshDatabase;

    private function xpath(): \DOMXPath
    {
        Channel::factory()->create(['name' => 'Loja Centro', 'status' => ChannelStatus::FAILED]);
        Ticket::factory()->for(Channel::query()->first())->create([
            'status' => TicketStatus::AGUARDANDO, 'waiting_since' => now()->subHour(), 'sla_due_at' => now()->subMinutes(5),
        ]);

        $html = (string) $this->actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->get('/inbox')->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($dom);
    }

    public function test_avisos_tem_uma_linha_compacta_e_texto_completo_no_title(): void
    {
        $xpath = $this->xpath();

        foreach (['connection-down', 'sla-breached'] as $kind) {
            $alert = $xpath->query('//*[@data-alert="'.$kind.'"]')->item(0);
            $text = $xpath->query('.//p', $alert)->item(0);

            $this->assertNotNull($alert, $kind);
            $this->assertStringContainsString('py-1.5', $alert->getAttribute('class'), $kind);
            $this->assertStringContainsString('text-xs', $alert->getAttribute('class'), $kind);
            $this->assertStringContainsString('truncate', $text->getAttribute('class'), $kind);
            $this->assertSame(trim(preg_replace('/\s+/', ' ', $text->textContent)), $text->getAttribute('title'), $kind);
        }

        $audio = $xpath->query('//*[@id="alerts-audio"]')->item(0);
        $this->assertStringContainsString('py-1.5', $audio->getAttribute('class'));
        $this->assertStringContainsString('truncate', $xpath->query('.//p', $audio)->item(0)->getAttribute('class'));
    }

    public function test_botoes_dos_avisos_sao_flux_button_pequeno(): void
    {
        $xpath = $this->xpath();
        $buttons = $xpath->query('//*[@data-alert="connection-down"]//*[@data-flux-button] | //*[@data-alert="sla-breached"]//*[@data-flux-button] | //*[@id="alerts-audio-enable"]');

        $this->assertGreaterThanOrEqual(4, $buttons->length);
        foreach ($buttons as $button) {
            $this->assertNotNull($button->attributes->getNamedItem('data-flux-button'), $button->textContent);
            $this->assertStringContainsString('h-6', $button->getAttribute('class'), trim($button->textContent));
        }
    }
}
