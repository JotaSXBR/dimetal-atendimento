<?php

namespace App\Livewire\Settings;

use App\Enums\ChannelStatus;
use App\Enums\TicketStatus;
use App\Livewire\Settings\Concerns\SendsTestMessage;
use App\Models\Channel;
use App\Models\Ticket;
use App\Services\Waha\ChannelPhone;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaQr;
use App\Services\Waha\WahaRequestException;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

/**
 * Um número de WhatsApp na tela de Canais: conexão, QR, pareamento e texto de teste.
 */
class ChannelCard extends Component
{
    use SendsTestMessage;

    public Channel $channel;

    private const ACTIONS = ['deactivate', 'delete-session', 'delete-channel'];

    public ?string $qr = null;

    /** O texto cru do QR, para o navegador desenhar com a logo; `qr` (PNG do WAHA) é o fallback. */
    public ?string $qrValue = null;

    /** Depois de uma falha no desenho do navegador, o cartão fica na imagem do WAHA. */
    public bool $preferPng = false;

    public ?string $confirming = null;

    public bool $sessionMissing = false;

    public ?string $errorMessage = null;

    public string $channelName = '';

    public string $authMethod = 'qr';

    public string $pairingPhone = '';

    public ?string $pairingCode = null;

    public bool $backfill = false;

    public int $backfillDays = 5;

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

    /**
     * Desvincula o aparelho e marca o canal como desligado de propósito: sem alerta de queda e sem resposta nos tickets dele.
     * Logout e não só parar: o WAHA religa as sessões paradas quando reinicia, e a desvinculada sobe em Aguardando QR.
     */
    public function deactivate(): void
    {
        $this->reset('errorMessage', 'qr', 'qrValue', 'pairingCode', 'confirming');

        try {
            (new WahaClient($this->channel))->logout();
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->channel->active = false;
        $this->setStatus(ChannelStatus::STOPPED);
    }

    /** O canal volta a valer e a sessão inicia: o aparelho foi desvinculado, então aparece o QR para ler de novo. */
    public function reactivate(): void
    {
        $this->reset('errorMessage');
        $this->channel->update(['active' => true]);

        try {
            (new WahaClient($this->channel))->start();
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->setStatus(ChannelStatus::STARTING);
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

        // A sessão sai do WAHA antes da linha: uma sessão viva sem canal receberia mensagens que o app descarta.
        try {
            (new WahaClient($this->channel))->deleteSession();
        } catch (Throwable $exception) {
            $this->confirming = null;
            $this->fail($exception);

            return;
        }

        $this->channel->delete();
        $this->dispatch('channel-deleted');
    }

    public function refreshStatus(): void
    {
        try {
            $session = (new WahaClient($this->channel))->session();
            $newStatus = $session['status'] ?? null;
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

        $me = data_get($session, 'me.id');
        app(ChannelPhone::class)->sync($this->channel, is_string($me) ? $me : null);

        if ($status?->needsQr() && $this->channel->active) {
            $this->refreshQr();
        } elseif ($status !== null) {
            $this->reset('qr', 'qrValue');
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

    public function render(): View
    {
        $tickets = Ticket::query()->where('channel_id', $this->channel->id);

        return view('livewire.settings.channel-card', [
            'hasHistory' => $tickets->exists(),
            // A contagem só importa na janela de confirmação.
            'openTickets' => $this->confirming === null || $this->confirming === 'delete-channel'
                ? 0
                : $tickets->where('status', '<>', TicketStatus::ENCERRADO->value)->count(),
        ]);
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
            $waha = new WahaQr(new WahaClient($this->channel));

            // O texto cru vai para o navegador desenhar com a logo; o PNG do WAHA só quando o texto não vem ou o desenho falhou.
            $this->qrValue = $this->preferPng ? null : $waha->raw();
            $this->qr = $this->qrValue === null ? $waha->dataUri() : null;
        } catch (Throwable $exception) {
            $this->reset('qr', 'qrValue');
            $this->fail($exception);
        }
    }

    /** O navegador não conseguiu desenhar o QR (biblioteca bloqueada, por exemplo): volta à imagem do WAHA. */
    public function qrFallback(): void
    {
        $this->preferPng = true;
        $this->refreshQr();
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
