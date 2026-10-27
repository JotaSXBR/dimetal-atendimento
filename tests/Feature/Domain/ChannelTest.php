<?php

namespace Tests\Feature\Domain;

use App\Enums\ChannelStatus;
use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_canal_nasce_parado_e_com_backfill_desligado(): void
    {
        $channel = Channel::factory()->create();

        $this->assertSame(ChannelStatus::STOPPED, $channel->status);
        $this->assertSame(0, $channel->backfill_days);
        $this->assertFalse($channel->isConnected());
    }

    public function test_importacao_de_historico_e_opt_in_com_dias_escolhidos(): void
    {
        $channel = Channel::factory()->create(['backfill_days' => 5]);

        $this->assertSame(5, $channel->fresh()->backfill_days);
    }

    public function test_normaliza_telefone_para_chat_id(): void
    {
        $this->assertSame(
            '5516998124477@c.us',
            Channel::factory()->make()->normalizeChatId('+55 (16) 99812-4477')
        );
    }

    /** @return array<string, array{ChannelStatus, string}> */
    public static function rotulos(): array
    {
        return [
            'conectado' => [ChannelStatus::WORKING, 'Conectado'],
            'qr' => [ChannelStatus::SCAN_QR_CODE, 'Aguardando QR'],
            'falhou' => [ChannelStatus::FAILED, 'Falhou'],
        ];
    }

    #[DataProvider('rotulos')]
    public function test_cada_status_tem_rotulo_em_portugues(ChannelStatus $status, string $label): void
    {
        $this->assertSame($label, $status->label());
    }

    public function test_status_de_qr_pede_leitura_e_falha_merece_alerta(): void
    {
        $this->assertTrue(ChannelStatus::SCAN_QR_CODE->needsQr());
        $this->assertFalse(ChannelStatus::WORKING->needsQr());
        $this->assertTrue(ChannelStatus::FAILED->isProblem());
        $this->assertFalse(ChannelStatus::STARTING->isProblem());
    }
}
