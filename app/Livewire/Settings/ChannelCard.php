<?php

namespace App\Livewire\Settings;

use App\Enums\ChannelStatus;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Ticket;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaRequestException;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

/**
 * Um número de WhatsApp na tela de Canais: conexão, QR, pareamento e texto de teste.
 */
class ChannelCard extends Component
{
    public Channel $channel;

    private const ACTIONS = ['deactivate', 'delete-session', 'delete-channel'];

    public ?string $qr = null;

    /** O texto cru do QR, para o navegador desenhar com a logo; `qr` (PNG do WAHA) é o fallback. */
    public ?string $qrValue = null;

    public ?string $confirming = null;

    public bool $sessionMissing = false;

    public ?string $errorMessage = null;

    public string $channelName = '';

    public string $authMethod = 'qr';

    public string $pairingPhone = '';

    public ?string $pairingCode = null;

    public bool $backfill = false;

    public int $backfillDays = 5;

    public string $testPhone = '';

    public string $testText = 'Teste do painel de atendimento';

    public ?string $sentMessage = null;

    public function mount(Channel $channel): void
    {
        $this->channel = $channel;
        $this->channelName = $channel->name;
    }

    public function connect(): void
    {
        $this->reset('errorMessage', 'sentMessage');

        $this->validate([
            'backfillDays' => $this->backfill
                ? ['required', 'integer', 'min:1', 'max:365']
                : ['nullable'],
        ]);

        $this->channel->backfill_days = $this->backfill ? $this->backfillDays : 0;
        $this->channel->save();

        try {
            (new WahaClient($this->channel))->start();
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->setStatus(ChannelStatus::STARTING);
    }

    public function rename(): void
    {
        $this->validate(['channelName' => ['required', 'string', 'max:60']]);

        $this->channel->update(['name' => trim($this->channelName)]);
    }

    public function disconnect(): void
    {
        $this->reset('errorMessage', 'qr', 'pairingCode');

        try {
            (new WahaClient($this->channel))->stop();
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->setStatus(ChannelStatus::STOPPED);
    }

    /** Pede a confirmação (com a contagem de tickets abertos) antes de uma ação que derruba o atendimento do canal. */
    public function ask(string $action): void
    {
        $this->confirming = in_array($action, self::ACTIONS, true) ? $action : null;
    }

    public function cancel(): void
    {
        $this->confirming = null;
    }

    /** Para a sessão e marca o canal como desligado de propósito: sem alerta de queda e sem resposta nos tickets dele. */
    public function deactivate(): void
    {
        $this->reset('errorMessage', 'qr', 'qrValue', 'pairingCode', 'confirming');

        try {
            (new WahaClient($this->channel))->stop();
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->channel->active = false;
        $this->setStatus(ChannelStatus::STOPPED);
    }

    /** Só volta a valer o canal; conectar continua sendo uma ação do dono. */
    public function reactivate(): void
    {
        $this->channel->update(['active' => true]);
    }

    /** Remove a sessão no WAHA. O canal e os tickets ficam; readicionar a mesma sessão os reaproveita. */
    public function deleteSession(): void
    {
        $this->reset('errorMessage', 'qr', 'qrValue', 'pairingCode', 'confirming');

        try {
            (new WahaClient($this->channel))->deleteSession();
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->sessionMissing = true;
        $this->setStatus(ChannelStatus::STOPPED);
    }

    /** Remove a linha do canal, só se ele nunca atendeu ninguém (ticket e mensagem apontam para o canal). */
    public function deleteChannel(): void
    {
        if (Ticket::query()->where('channel_id', $this->channel->id)->exists()) {
            $this->errorMessage = 'Este canal tem histórico de atendimento e não pode ser apagado.';
            $this->confirming = null;

            return;
        }

        $this->channel->delete();
        $this->dispatch('channel-deleted');
    }

    public function openTickets(): int
    {
        return Ticket::query()->where('channel_id', $this->channel->id)->where('status', '<>', TicketStatus::ENCERRADO->value)->count();
    }

    public function refreshStatus(): void
    {
        try {
            $newStatus = (new WahaClient($this->channel))->session()['status'] ?? null;
        } catch (WahaRequestException $exception) {
            if ($exception->statusCode === 404) {
                // Sessão que não existe no WAHA (excluída): não é erro, e o canal fica parado.
                $this->sessionMissing = true;
                $this->setStatus(ChannelStatus::STOPPED);

                return;
            }

            $this->fail($exception);

            return;
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->sessionMissing = false;
        $status = is_string($newStatus) ? ChannelStatus::tryFrom($newStatus) : null;

        if ($status !== null) {
            $this->setStatus($status);
        }

        if ($status?->needsQr()) {
            $this->refreshQr();
        }
    }

    public function requestPairingCode(): void
    {
        $this->reset('errorMessage', 'pairingCode');

        $phone = $this->onlyDigits($this->pairingPhone);

        if (! preg_match('/^\d{12,13}$/', $phone)) {
            $this->addError('pairingPhone', 'Informe o telefone com DDI e DDD, só números.');

            return;
        }

        try {
            $this->pairingCode = (new WahaClient($this->channel))->requestPairingCode($phone);
            $this->refreshQr();
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    public function sendTest(): void
    {
        $this->reset('errorMessage', 'sentMessage');

        $this->validate([
            'testPhone' => ['required', 'string'],
            'testText' => ['required', 'string', 'max:4096'],
        ]);

        $phone = $this->onlyDigits($this->testPhone);

        if (! preg_match('/^\d{12,13}$/', $phone)) {
            $this->addError('testPhone', 'Informe o telefone com DDI e DDD, só números.');

            return;
        }

        try {
            $response = (new WahaClient($this->channel))->sendText($this->channel->normalizeChatId($phone), $this->testText);
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->leaveOffline();

        $id = $response['id'] ?? null;
        $this->sentMessage = 'Enviado: '.(is_string($id) ? $id : 'sem id');
    }

    public function render(): View
    {
        return view('livewire.settings.channel-card');
    }

    private function setStatus(ChannelStatus $status): void
    {
        $this->channel->status = $status;
        $this->channel->last_event_at = now();
        $this->channel->save();
    }

    private function refreshQr(): void
    {
        try {
            $this->qr = (new WahaClient($this->channel))->qrDataUri();
        } catch (Throwable $exception) {
            $this->qr = null;
            $this->fail($exception);
        }
    }

    /**
     * Depois de enviar, o WAHA precisa voltar a ficar offline para o celular
     * do atendente receber notificação — presença online segura o push.
     */
    private function leaveOffline(): void
    {
        try {
            (new WahaClient($this->channel))->setPresence('offline');
        } catch (Throwable) {
            // Presença é best-effort: a mensagem já foi enviada.
        }
    }

    private function fail(Throwable $exception): void
    {
        $this->errorMessage = 'Não foi possível falar com o WAHA: '.$exception->getMessage();
    }

    private function onlyDigits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }
}
