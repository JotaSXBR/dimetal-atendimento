@php
    $status = $channel->status;
    $inactive = ! $channel->active;
    $tone = $inactive ? 'ink-3' : match ($status) {
        \App\Enums\ChannelStatus::WORKING => 'accent',
        \App\Enums\ChannelStatus::FAILED => 'danger',
        \App\Enums\ChannelStatus::SCAN_QR_CODE, \App\Enums\ChannelStatus::PASSKEY_REQUIRED, \App\Enums\ChannelStatus::PASSKEY_CONFIRMATION_REQUIRED => 'warn',
        default => 'ink-3',
    };
    $dot = match ($tone) {
        'accent' => 'bg-accent',
        'danger' => 'bg-danger',
        'warn' => 'bg-warn',
        default => 'bg-ink-3',
    };
    $badge = match ($tone) {
        'accent' => 'bg-accent-soft text-accent-deep',
        'danger' => 'bg-danger/10 text-danger',
        'warn' => 'bg-warn/10 text-warn',
        default => 'bg-canvas text-ink-2',
    };
    $label = $inactive ? 'Desativado' : ($sessionMissing ? 'Sessão removida' : $status->label());
    $hint = match (true) {
        $inactive => 'Desligado de propósito: sem alertas de queda e sem resposta nos tickets deste número.',
        $sessionMissing => 'A sessão não existe mais no WAHA. Conecte para criá-la de novo e reaproveitar os tickets.',
        $status === \App\Enums\ChannelStatus::WORKING => 'Sessão ativa. Para trocar o aparelho, gere um novo QR.',
        $status === \App\Enums\ChannelStatus::SCAN_QR_CODE => 'Escaneie com o WhatsApp deste número para conectar.',
        default => 'Use Conectar para iniciar a sessão deste número.',
    };
    $primary = $status === \App\Enums\ChannelStatus::WORKING ? 'Reconectar' : ($status === \App\Enums\ChannelStatus::SCAN_QR_CODE ? 'Gerar novo QR' : 'Conectar');
    $confirmAction = ['deactivate' => 'deactivate', 'delete-session' => 'deleteSession', 'delete-channel' => 'deleteChannel'][$confirming ?? ''] ?? null;
    $confirmLabel = ['deactivate' => 'Desativar', 'delete-session' => 'Excluir sessão', 'delete-channel' => 'Apagar canal'][$confirming ?? ''] ?? '';
@endphp

<div wire:poll.5s="refreshStatus" class="space-y-4">
    <div class="rounded-[14px] border border-line bg-surface p-5">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-ink">{{ $channel->name }}</p>
                <p class="mt-0.5 font-mono text-xs text-ink-3">{{ $channel->phone ?? 'número ainda não lido' }}</p>
            </div>

            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium {{ $badge }}">
                <span class="size-1.5 rounded-full {{ $dot }}"></span>
                {{ $label }}
            </span>
        </div>

        <div class="mt-4 flex gap-4">
            @if (! $inactive && $status === \App\Enums\ChannelStatus::SCAN_QR_CODE && $qrValue)
                {{-- Desenhado no navegador com a logo; o `wire:ignore` impede o poll de apagar o canvas. A chave nova a cada QR redesenha. --}}
                <div wire:key="qr-{{ md5($qrValue) }}" x-data="channelQr($wire, @js($qrValue), '{{ asset('images/qr-logo.svg') }}')" class="shrink-0">
                    <div wire:ignore x-ref="canvas" class="size-56 rounded-[10px] border border-line bg-white p-2 [&>canvas]:size-full" role="img" aria-label="QR do WhatsApp"></div>
                </div>
            @elseif (! $inactive && $status === \App\Enums\ChannelStatus::SCAN_QR_CODE && $qr)
                <img src="{{ $qr }}" alt="QR do WhatsApp" class="size-56 shrink-0 rounded-[10px] border border-line bg-white p-2">
            @else
                <div class="grid size-24 shrink-0 place-items-center rounded-[10px] border border-line bg-canvas text-ink-3" aria-hidden="true">
                    <flux:icon.qr-code class="size-8" />
                </div>
            @endif

            <div class="min-w-0 flex-1">
                <p class="text-xs leading-relaxed text-ink-2">{{ $hint }}</p>

                <dl class="mt-3 space-y-1 text-xs">
                    <div class="flex justify-between gap-3"><dt class="text-ink-3">Engine</dt><dd class="font-mono text-ink-2">{{ config('services.waha.engine', 'GOWS') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-3">Sessão</dt><dd class="truncate font-mono text-ink-2">{{ $channel->waha_session }}</dd></div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-3">Último evento</dt>
                        <dd class="font-mono text-ink-2">{{ $channel->last_event_at?->timezone(config('app.display_timezone'))->format('d/m/Y H:i:s') ?? '—' }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        @if (! $inactive && $status === \App\Enums\ChannelStatus::SCAN_QR_CODE && ! $qrValue && ! $qr)
            <p class="mt-2 text-xs text-ink-3">Aguardando o QR do WAHA…</p>
        @elseif (! $inactive && $status === \App\Enums\ChannelStatus::SCAN_QR_CODE)
            <p class="mt-2 text-xs text-ink-3">O QR é renovado automaticamente.</p>
        @endif

        @if (! $inactive)
            <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-line pt-4">
                <flux:checkbox wire:model="backfill" label="Importar histórico" />
                <flux:input wire:model="backfillDays" type="number" min="1" max="365" class="max-w-24" :disabled="! $backfill" />
                <span class="text-xs text-ink-3">dias</span>
                <p class="basis-full text-xs leading-relaxed text-ink-3">Desmarcado por padrão. A importação roda depois da conexão e não bloqueia o atendimento.</p>
                @error('backfillDays')
                    <p class="basis-full text-xs text-danger">{{ $message }}</p>
                @enderror
            </div>
        @endif

        <div class="mt-4 flex flex-wrap items-center gap-2">
            @if ($inactive)
                <x-ui.button variant="primary" wire:click="reactivate" wire:loading.attr="disabled">Reativar</x-ui.button>
            @else
                <x-ui.button variant="primary" wire:click="connect" wire:loading.attr="disabled">{{ $primary }}</x-ui.button>
                <x-ui.button variant="secondary" wire:click="disconnect" wire:loading.attr="disabled">Desconectar</x-ui.button>
                <x-ui.button variant="secondary" wire:click="ask('deactivate')">Desativar</x-ui.button>
            @endif
            <x-ui.button variant="secondary" wire:click="ask('delete-session')">Excluir sessão</x-ui.button>
            @unless ($hasHistory)
                <x-ui.button variant="ghost" wire:click="ask('delete-channel')">Apagar canal</x-ui.button>
            @endunless
        </div>

        @if ($confirmAction)
            <div class="mt-3 rounded-[10px] border border-warn/30 bg-warn/10 px-3 py-2 text-xs text-warn" role="alert">
                @if ($confirming === 'delete-channel')
                    Apagar este canal de vez? Ele nunca atendeu ninguém, então nada é perdido. A sessão também sai do WAHA.
                @elseif ($openTickets > 0)
                    Este canal tem {{ $openTickets }} {{ $openTickets === 1 ? 'ticket aberto' : 'tickets abertos' }}. Eles continuam no painel, mas não dá para responder até o canal voltar.
                @else
                    Este canal não tem tickets abertos. Os tickets e as mensagens continuam guardados.
                @endif
                @if ($confirming === 'delete-session' && $channel->active)
                    Um canal ativo sem sessão conta como queda e dispara o alerta de canal desconectado. Para parar sem alerta, use Desativar.
                @endif
                <div class="mt-2 flex gap-2">
                    <x-ui.button variant="primary" wire:click="{{ $confirmAction }}" wire:loading.attr="disabled">Confirmar {{ $confirmLabel }}</x-ui.button>
                    <x-ui.button variant="secondary" wire:click="cancel">Cancelar</x-ui.button>
                </div>
            </div>
        @endif

        @if ($errorMessage)
            <p class="mt-3 rounded-[10px] border border-danger/30 bg-danger/5 px-3 py-2 text-xs text-danger">
                {{ $errorMessage }}
            </p>
        @endif

        <details class="group mt-4 border-t border-line pt-3">
            <summary class="cursor-pointer text-xs font-semibold text-ink-2 hover:text-ink">Mais opções</summary>

            <div class="mt-3 space-y-5">
        <div class="flex items-end gap-2">
            <div class="flex-1">
                <flux:input wire:model="channelName" label="Nome do canal" />
                @error('channelName')
                    <p class="mt-2 text-xs text-danger">{{ $message }}</p>
                @enderror
            </div>
            <flux:button wire:click="rename" size="sm">Salvar nome</flux:button>
        </div>


            @if (! $inactive && $status === \App\Enums\ChannelStatus::SCAN_QR_CODE)
                        <p class="text-sm font-semibold text-ink">Conectar com código de pareamento</p>

                        <div class="mt-3 flex gap-2">
                            <button
                                type="button"
                                wire:click="$set('authMethod', 'qr')"
                                class="rounded-[10px] px-3 py-1.5 text-[13px] font-semibold {{ $authMethod === 'qr' ? 'bg-accent text-white' : 'border border-line text-ink-2' }}"
                            >
                                QR
                            </button>
                            <button
                                type="button"
                                wire:click="$set('authMethod', 'code')"
                                class="rounded-[10px] px-3 py-1.5 text-[13px] font-semibold {{ $authMethod === 'code' ? 'bg-accent text-white' : 'border border-line text-ink-2' }}"
                            >
                                Código de pareamento
                            </button>
                        </div>

                        @if ($authMethod === 'code')
                            <div class="mt-4 space-y-3">
                                <flux:input
                                    wire:model="pairingPhone"
                                    label="Telefone do WhatsApp"
                                    placeholder="+55 (16) 99812-4477"
                                />
                                @error('pairingPhone')
                                    <p class="text-xs text-danger">{{ $message }}</p>
                                @enderror

                                <x-ui.button variant="secondary" wire:click="requestPairingCode" wire:loading.attr="disabled">
                                    Gerar código
                                </x-ui.button>

                                @if ($pairingCode)
                                    <div class="rounded-[10px] bg-canvas px-4 py-3">
                                        <p class="font-mono text-2xl tracking-widest text-ink">{{ $pairingCode }}</p>
                                        <p class="mt-1 text-xs text-ink-3">
                                            WhatsApp → Aparelhos conectados → Conectar aparelho → Conectar com número de telefone.
                                        </p>
                                    </div>
                                @endif
                            </div>
                        @endif
            @endif
            @if (! $inactive && $status === \App\Enums\ChannelStatus::WORKING)
                        <p class="text-sm font-semibold text-ink">Enviar texto de teste</p>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <flux:input wire:model="testPhone" label="Telefone" placeholder="+55 (16) 99812-4477" />
                            <flux:input wire:model="testText" label="Mensagem" />
                        </div>

                        @error('testPhone')
                            <p class="mt-2 text-xs text-danger">{{ $message }}</p>
                        @enderror
                        @error('testText')
                            <p class="mt-2 text-xs text-danger">{{ $message }}</p>
                        @enderror

                        <div class="mt-3">
                            <x-ui.button variant="secondary" wire:click="sendTest" wire:loading.attr="disabled">
                                Enviar teste
                            </x-ui.button>
                        </div>

                        @if ($sentMessage)
                            <p class="mt-3 font-mono text-xs text-accent-deep">{{ $sentMessage }}</p>
                        @endif
            @endif
            </div>
        </details>
    </div>
</div>
