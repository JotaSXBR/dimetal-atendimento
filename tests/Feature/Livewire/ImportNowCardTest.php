<?php

namespace Tests\Feature\Livewire;

use App\Enums\ChannelStatus;
use App\Enums\UserRole;
use App\Jobs\ImportHistoryJob;
use App\Livewire\Settings\ChannelCard;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ImportNowCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());
        Queue::fake();
    }

    private function card(Channel $channel): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(ChannelCard::class, ['channel' => $channel]);
    }

    public function test_importar_agora_despacha_o_job_e_grava_os_dias(): void
    {
        $channel = Channel::factory()->connected()->create(['backfill_last_run_at' => now()->subDay()]);

        $this->card($channel)->set('backfillDays', 10)->call('importNow')->assertSee('Importação pedida');

        Queue::assertPushed(ImportHistoryJob::class, fn (ImportHistoryJob $job): bool => $job->channelId === $channel->id);
        $fresh = $channel->fresh();
        $this->assertSame(10, $fresh->backfill_days);
        $this->assertTrue($fresh->backfill_last_run_at?->equalTo(now()));
    }

    public function test_recusa_dias_fora_de_1_a_30(): void
    {
        $channel = Channel::factory()->connected()->create();

        $this->card($channel)->set('backfillDays', 31)->call('importNow')->assertHasErrors(['backfillDays'])->assertSee('No máximo 30 dias');
        $this->card($channel)->set('backfillDays', 0)->call('importNow')->assertHasErrors(['backfillDays']);

        Queue::assertNotPushed(ImportHistoryJob::class);
    }

    public function test_canal_desconectado_nao_mostra_o_botao_nem_despacha(): void
    {
        $channel = Channel::factory()->create(['status' => ChannelStatus::STOPPED]);

        $this->card($channel)->assertDontSee('Importar agora')->call('importNow');

        Queue::assertNotPushed(ImportHistoryJob::class);
    }

    public function test_botao_pede_confirmacao(): void
    {
        $this->card(Channel::factory()->connected()->create())
            ->assertSee('Importar agora')
            ->assertSeeHtml('wire:confirm=');
    }
}
