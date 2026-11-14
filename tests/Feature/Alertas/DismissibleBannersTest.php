<?php

namespace Tests\Feature\Alertas;

use App\Enums\ChannelStatus;
use App\Enums\TicketStatus;
use App\Livewire\Alerts\Banner;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** As faixas fixas do topo têm um ✕ que as esconde por um tempo (resources/js/alert-dismiss.js); aqui, o que o servidor entrega. */
class DismissibleBannersTest extends TestCase
{
    use RefreshDatabase;

    private function breachedTickets(User $owner, int $count): void
    {
        Ticket::factory()->count($count)->create([
            'channel_id' => Channel::factory()->create(['status' => ChannelStatus::WORKING])->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $owner->id,
            'waiting_since' => now()->subHour(),
            'sla_due_at' => now()->subMinutes(5),
        ]);
    }

    public function test_faixa_do_whatsapp_tem_x_e_assinatura_dos_canais_fora_do_ar(): void
    {
        $a = Channel::factory()->create(['status' => ChannelStatus::FAILED]);
        $this->actingAs(User::factory()->create());

        $html = Livewire::test(Banner::class)->html();

        $this->assertStringContainsString('data-dismiss="whatsapp"', $html);
        $this->assertStringContainsString('data-signature="'.$a->id.'@', $html);
        $this->assertStringContainsString('aria-label="Dispensar aviso de', $html);
        $this->assertStringContainsString('data-dismiss-button', $html);
    }

    public function test_a_assinatura_do_whatsapp_muda_quando_outro_canal_cai(): void
    {
        $a = Channel::factory()->create(['status' => ChannelStatus::FAILED]);
        $this->actingAs(User::factory()->create());

        $before = Livewire::test(Banner::class)->html();
        $b = Channel::factory()->create(['status' => ChannelStatus::FAILED, 'waha_session' => 'segundo']);
        $after = Livewire::test(Banner::class)->html();

        $this->assertNotSame($this->signature($before, 'whatsapp'), $this->signature($after, 'whatsapp'));
        $this->assertStringContainsString((string) $b->id.'@', $this->signature($after, 'whatsapp'));
        $this->assertStringContainsString($a->id.'@', $this->signature($after, 'whatsapp'));
    }

    public function test_faixa_do_sla_tem_x_e_a_assinatura_e_a_contagem(): void
    {
        $user = User::factory()->create();
        $this->breachedTickets($user, 3);
        $this->actingAs($user);

        $html = Livewire::test(Banner::class)->html();

        $this->assertStringContainsString('data-dismiss="sla"', $html);
        $this->assertSame('3', $this->signature($html, 'sla'));
        $this->assertStringContainsString('aria-label="Dispensar aviso de', $html);
    }

    public function test_faixas_do_navegador_tem_x_e_tipo(): void
    {
        $html = (string) $this->blade('<x-alerts-local />');

        $this->assertStringContainsString('data-dismiss="offline"', $html);
        $this->assertStringContainsString('data-signature="0"', $html);
        $this->assertStringContainsString('data-dismiss="audio"', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'aria-label="Dispensar aviso de'));
        $this->assertSame(2, substr_count($html, 'data-dismiss-button="'));
    }

    private function signature(string $html, string $kind): string
    {
        preg_match('/data-dismiss="'.$kind.'"[^>]*data-signature="([^"]*)"/', $html, $match) || preg_match('/data-signature="([^"]*)"[^>]*data-dismiss="'.$kind.'"/', $html, $match);

        return $match[1] ?? '';
    }
}
