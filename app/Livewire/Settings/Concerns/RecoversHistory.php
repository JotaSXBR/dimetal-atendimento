<?php

namespace App\Livewire\Settings\Concerns;

use App\Jobs\ImportHistoryJob;
use App\Jobs\RecoverMessagesJob;
use Illuminate\Validation\Rule;

/**
 * As duas ações do cartão do canal que puxam mensagens do WhatsApp para dentro do sistema: recuperar uma janela de horas e
 * importar os últimos dias. Usa `channel` do componente.
 */
trait RecoversHistory
{
    /** Janela, em horas, da recuperação manual de mensagens. */
    public int $recoverHours = 24;

    public ?string $recoverNotice = null;

    public ?string $importNotice = null;

    /** Dias que o botão "Importar agora" traz (o campo `backfillDays` é só da primeira conexão). */
    public int $importDays = 5;

    /**
     * Importa o histórico dos últimos `importDays` dias de novo (a importação da primeira conexão roda uma vez só).
     * Idempotente: o que já existe é ignorado. O resultado chega pelo sino.
     */
    public function importNow(): void
    {
        $this->reset('importNotice');
        $this->validate(['importDays' => ['required', Rule::in([1, 5, 15, 30])]], ['importDays.in' => 'Escolha uma das opções.']);
        $this->channel->refresh();

        if (! $this->channel->active || ! $this->channel->isConnected()) {
            return;
        }

        $this->channel->forceFill(['backfill_days' => $this->importDays, 'backfill_last_run_at' => now()])->save();
        ImportHistoryJob::dispatch($this->channel->id);

        $this->importNotice = 'Importação pedida. Se já houver uma em andamento neste canal, ela é reaproveitada. O resultado aparece no sino.';
    }

    /** Puxa de novo as mensagens da janela escolhida; repetir não duplica nada. O resultado chega pelo sino. */
    public function recover(): void
    {
        $this->reset('recoverNotice');
        $this->validate(['recoverHours' => ['required', Rule::in([1, 6, 24, 72])]], ['recoverHours.in' => 'Escolha uma das janelas da lista.']);
        $this->channel->refresh();

        if (! $this->channel->active || ! $this->channel->isConnected()) {
            return;
        }

        RecoverMessagesJob::dispatch($this->channel->id, now()->subHours($this->recoverHours)->toImmutable(), now()->toImmutable(), notifyAlways: true);

        $this->recoverNotice = 'Recuperação pedida. Se já houver uma em andamento neste canal, ela é reaproveitada. O resultado aparece no sino.';
    }
}
