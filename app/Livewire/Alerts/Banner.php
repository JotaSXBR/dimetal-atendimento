<?php

namespace App\Livewire\Alerts;

use App\Models\Channel;
use App\Services\Alerts\AlertRecipients;
use App\Support\ConnectionState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * As faixas fixas do topo: WhatsApp fora do ar (crítico, lido do banco, então quem abre a tela depois também vê),
 * confirmação de que voltou (dispensada em 10 s no navegador) e SLA estourado dos tickets que o usuário vê.
 * A região `role="alert"` fica sempre na página, vazia, para o leitor de tela anunciar o texto quando ele aparecer.
 */
class Banner extends Component
{
    public bool $inset = false;

    public string $recovered = '';

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'echo-private:inbox,.channel.status' => 'onChannelStatus',
            'echo-private:users.'.Auth::id().',.Illuminate\Notifications\Events\BroadcastNotificationCreated' => '$refresh',
            'echo-private:inbox,.ticket.updated' => '$refresh',
            'alerts-refresh' => '$refresh',
        ];
    }

    /** @param  array<string, mixed>  $payload */
    public function onChannelStatus(array $payload): void
    {
        $id = $payload['channel_id'] ?? null;
        $channel = is_numeric($id) ? Channel::query()->find((int) $id) : null;

        if (($payload['status'] ?? '') === 'WORKING' && $channel !== null) {
            $this->recovered = $channel->name;
        }
    }

    public function dismissRecovered(): void
    {
        $this->recovered = '';
    }

    public function mute(int $minutes): void
    {
        $user = Auth::user();

        if ($user?->muteCriticalFor($minutes)) {
            $this->dispatch('alert-settings-updated', settings: $user->alertSettings()->toArray());
        }
    }

    public function render(): View
    {
        $user = Auth::user();
        $down = ConnectionState::down();

        return view('livewire.alerts.banner', [
            'down' => $down,
            // Voltou, mas outro canal segue caído: a faixa crítica já diz isso, não mostra a confirmação por cima.
            'recovered' => $down->isEmpty() ? $this->recovered : '',
            'breached' => $user === null ? 0 : app(AlertRecipients::class)->breachedFor($user)->count(),
            'canSeeChannels' => $user?->isAdmin() ?? false,
        ]);
    }
}
