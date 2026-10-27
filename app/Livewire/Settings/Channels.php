<?php

namespace App\Livewire\Settings;

use App\Enums\ChannelStatus;
use App\Models\Channel;
use App\Models\WahaEvent;
use App\Services\Waha\WahaClient;
use App\Settings\WahaSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Title('Canais')]
class Channels extends Component
{
    public ?Channel $channel = null;

    public ?string $qr = null;

    /** @var array<int, array{event: string, session: string|null, at: string|null}> */
    public array $lastEvents = [];

    public ?string $errorMessage = null;

    public string $authMethod = 'qr';

    public string $pairingPhone = '';

    public ?string $pairingCode = null;

    public bool $backfill = false;

    public int $backfillDays = 5;

    public string $testPhone = '';

    public string $testText = 'Teste do painel de atendimento';

    public ?string $sentMessage = null;

    public function mount(): void
    {
        $this->channel = Channel::firstOrCreate(
            ['waha_session' => WahaSettings::sessionName() ?: 'main'],
            ['name' => 'WhatsApp principal'],
        );

        $this->loadEvents();
    }

    public function connect(): void
    {
        $this->reset('errorMessage', 'sentMessage');

        $this->validate([
            'backfillDays' => $this->backfill
                ? ['required', 'integer', 'min:1', 'max:365']
                : ['nullable'],
        ]);

        $channel = $this->channel();

        $channel->backfill_days = $this->backfill ? $this->backfillDays : 0;
        $channel->save();

        try {
            (new WahaClient($channel))->start();
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->setStatus($channel, ChannelStatus::STARTING);
    }

    public function disconnect(): void
    {
        $this->reset('errorMessage', 'qr', 'pairingCode');

        try {
            (new WahaClient($this->channel()))->stop();
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->setStatus($this->channel(), ChannelStatus::STOPPED);
    }

    public function refreshStatus(): void
    {
        $channel = $this->channel();

        try {
            $newStatus = (new WahaClient($channel))->session()['status'] ?? null;
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $status = is_string($newStatus) ? ChannelStatus::tryFrom($newStatus) : null;

        if ($status !== null) {
            $this->setStatus($channel, $status);
        }

        $this->loadEvents();

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
            $this->pairingCode = (new WahaClient($this->channel()))->requestPairingCode($phone);
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

        $channel = $this->channel();

        try {
            $response = (new WahaClient($channel))->sendText($channel->normalizeChatId($phone), $this->testText);
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->leaveOffline($channel);

        $this->sentMessage = 'Enviado: '.($response['id'] ?? 'sem id');
    }

    public function render(): View
    {
        return view('livewire.settings.channels');
    }

    private function channel(): Channel
    {
        return $this->channel ??= Channel::firstOrCreate(
            ['waha_session' => WahaSettings::sessionName() ?: 'main'],
            ['name' => 'WhatsApp principal'],
        );
    }

    private function setStatus(Channel $channel, ChannelStatus $status): void
    {
        $channel->status = $status;
        $channel->last_event_at = now();
        $channel->save();

        $this->channel = $channel->fresh();
    }

    private function refreshQr(): void
    {
        try {
            $this->qr = (new WahaClient($this->channel()))->qrDataUri();
        } catch (Throwable $exception) {
            $this->qr = null;
            $this->fail($exception);
        }
    }

    /**
     * Depois de enviar, o WAHA precisa voltar a ficar offline para o celular
     * do atendente receber notificação — presença online segura o push.
     */
    private function leaveOffline(Channel $channel): void
    {
        try {
            (new WahaClient($channel))->setPresence('offline');
        } catch (Throwable) {
            // Presença é best-effort: a mensagem já foi enviada.
        }
    }

    private function fail(Throwable $exception): void
    {
        $this->errorMessage = 'Não foi possível falar com o WAHA: '.$exception->getMessage();
    }

    private function loadEvents(): void
    {
        $this->lastEvents = WahaEvent::query()
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (WahaEvent $event): array => [
                'event' => $event->event,
                'session' => $event->session,
                'at' => $event->received_at->format('d/m/Y H:i:s'),
            ])
            ->all();
    }

    private function onlyDigits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }
}
