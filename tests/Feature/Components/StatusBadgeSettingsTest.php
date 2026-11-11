<?php

namespace Tests\Feature\Components;

use App\Enums\ChannelStatus;
use App\Enums\GoogleConnectionStatus;
use App\Enums\UserRole;
use App\Livewire\Automation\Index as Automation;
use App\Livewire\Settings\ChannelCard;
use App\Livewire\Settings\GoogleContacts;
use App\Livewire\Settings\Users;
use App\Models\Channel;
use App\Models\GoogleConnection;
use App\Models\User;
use App\Services\Bot\WorkflowPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** O selo único nas telas de configuração e automação. */
class StatusBadgeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($dom);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    /** @return array<string, array{0: ChannelStatus, 1: string, 2: string}> */
    public static function canais(): array
    {
        return [
            'conectado' => [ChannelStatus::WORKING, 'ok', 'Conectado'],
            'falhou' => [ChannelStatus::FAILED, 'danger', 'Falhou'],
            'aguardando QR' => [ChannelStatus::SCAN_QR_CODE, 'warn', 'Aguardando QR'],
            'parado' => [ChannelStatus::STOPPED, 'neutral', 'Parado'],
        ];
    }

    #[DataProvider('canais')]
    public function test_cartao_do_canal_usa_o_selo_com_o_tom_do_status(ChannelStatus $status, string $tone, string $label): void
    {
        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        $channel = Channel::factory()->create(['status' => $status, 'active' => true]);

        $xpath = $this->xpath(Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])->html());

        $this->assertSame(1, $xpath->query('//*[@data-status-tone="'.$tone.'"][contains(., "'.$label.'")]')->length);
    }

    public function test_canal_desativado_e_neutro_e_sessao_removida_e_erro(): void
    {
        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        $off = Channel::factory()->create(['status' => ChannelStatus::WORKING, 'active' => false]);
        $on = Channel::factory()->create(['status' => ChannelStatus::STOPPED, 'active' => true, 'waha_session' => 'outra']);

        $inactive = $this->xpath(Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $off])->html());
        $this->assertSame(1, $inactive->query('//*[@data-status-tone="neutral"][contains(., "Desativado")]')->length);

        $missing = $this->xpath(Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $on])->set('sessionMissing', true)->html());
        $this->assertSame(1, $missing->query('//*[@data-status-tone="danger"][contains(., "Sessão removida")]')->length);
    }

    public function test_usuarios_mostram_papel_e_inativo_em_selo_neutro(): void
    {
        User::factory()->create(['role' => UserRole::ATENDENTE, 'name' => 'Maria Inativa', 'active' => false]);

        $xpath = $this->xpath(Livewire::actingAs($this->admin())->test(Users::class)->html());

        $this->assertGreaterThanOrEqual(1, $xpath->query('//*[@data-status-tone="neutral"][contains(., "Inativo")]')->length);
        $this->assertGreaterThanOrEqual(1, $xpath->query('//*[@data-status-tone="neutral"][contains(., "Atendente")]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-flux-badge]')->length, 'sem flux:badge na lista de usuários');
    }

    public function test_google_mostra_a_conexao_em_selo_com_o_tom_do_status(): void
    {
        foreach ([[GoogleConnectionStatus::CONNECTED, 'ok'], [GoogleConnectionStatus::NEEDS_REAUTH, 'warn']] as [$status, $tone]) {
            GoogleConnection::query()->delete();
            GoogleConnection::query()->create([
                'account_email' => 'dimetal@gmail.com', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour(), 'status' => $status, 'connected_at' => now(),
            ]);

            $xpath = $this->xpath(Livewire::actingAs($this->admin())->test(GoogleContacts::class)->html());

            $this->assertSame(1, $xpath->query('//*[@data-status-tone="'.$tone.'"][contains(., "'.$status->label().'")]')->length, $status->value);
        }
    }

    public function test_automacao_mostra_publicado_e_nunca_publicado_em_selo(): void
    {
        $user = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $publisher = app(WorkflowPublisher::class);
        $publisher->publish($publisher->create('Fluxo A', $user, template: true), $user);
        $publisher->create('Fluxo B', $user, template: true);

        $xpath = $this->xpath(Livewire::actingAs($user)->test(Automation::class)->html());

        $this->assertSame(1, $xpath->query('//*[@data-status-tone="ok"][contains(., "Publicado")]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-status-tone="neutral"][contains(., "Nunca publicado")]')->length);
    }
}
