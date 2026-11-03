<?php

namespace Tests\Feature\Livewire;

use App\Enums\ChannelStatus;
use App\Enums\UserRole;
use App\Jobs\RecoverMessagesJob;
use App\Livewire\Settings\ChannelCard;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class RecoverMessagesCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        Queue::fake();
    }

    private function card(Channel $channel): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(ChannelCard::class, ['channel' => $channel]);
    }

    public function test_recuperar_despacha_o_job_com_aviso_sempre(): void
    {
        $channel = Channel::factory()->connected()->create();

        $this->card($channel)->set('recoverHours', 6)->call('recover')->assertSee('Recuperação iniciada');

        Queue::assertPushed(RecoverMessagesJob::class, fn (RecoverMessagesJob $job): bool => $job->channelId === $channel->id
            && $job->notifyAlways
            && $job->from->equalTo(now()->subHours(6))
            && $job->to->equalTo(now()));
    }

    public function test_a_janela_padrao_e_de_24_horas(): void
    {
        $channel = Channel::factory()->connected()->create();

        $this->card($channel)->call('recover');

        Queue::assertPushed(RecoverMessagesJob::class, fn (RecoverMessagesJob $job): bool => $job->from->equalTo(now()->subHours(24)));
    }

    public function test_janela_invalida_e_recusada(): void
    {
        $channel = Channel::factory()->connected()->create();

        $this->card($channel)->set('recoverHours', 5)->call('recover')->assertHasErrors(['recoverHours']);

        Queue::assertNotPushed(RecoverMessagesJob::class);
    }

    public function test_canal_desconectado_nao_mostra_o_botao_nem_despacha(): void
    {
        $channel = Channel::factory()->create(['status' => ChannelStatus::STOPPED]);

        $this->card($channel)->assertDontSee('Recuperar mensagens')->call('recover');

        Queue::assertNotPushed(RecoverMessagesJob::class);
    }

    public function test_canal_conectado_mostra_o_botao(): void
    {
        $this->card(Channel::factory()->connected()->create())->assertSee('Recuperar mensagens');
    }
}
