<?php

namespace App\Livewire\Settings;

use App\Enums\ChannelStatus;
use App\Models\Channel;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

/**
 * Um número de WhatsApp na tela de Canais: conexão, QR, pareamento e texto de teste.
 */
class ChannelCard extends Component
{
    public Channel $channel;

    public ?string $qr = null;

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

    public function refreshStatus(): void
    {
        try {
            $newStatus = (new WahaClient($this->channel))->session()['status'] ?? null;
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

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

        $this->sentMessage = 'Enviado: '.($response['id'] ?? 'sem id');
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
