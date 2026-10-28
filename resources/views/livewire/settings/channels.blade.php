<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout heading="Canais" subheading="Conexão do WhatsApp">
        @php
            $status = $channel?->status;
            $tone = match ($status) {
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
        @endphp

        <div wire:poll.5s="refreshStatus" class="space-y-6">
            <div class="rounded-[14px] border border-line bg-surface p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold text-ink">{{ $channel?->name ?? 'Novo WhatsApp' }}</p>
                        <p class="mt-0.5 font-mono text-xs text-ink-3">
                            sessão {{ $channel?->waha_session ?? '—' }}
                            @if ($channel?->phone)
                                · {{ $channel->phone }}
                            @endif
                        </p>
                    </div>

                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium {{ $badge }}">
                        <span class="size-1.5 rounded-full {{ $dot }}"></span>
                        {{ $status?->label() ?? 'Parado' }}
                    </span>
                </div>

                <p class="mt-3 text-xs text-ink-3">
                    Último evento:
                    <span class="font-mono text-ink-2">
                        {{ $channel?->last_event_at?->format('d/m/Y H:i:s') ?? 'nenhum' }}
                    </span>
                </p>

                @if (! $channel)
                    <div class="mt-4">
                        <flux:input wire:model="sessionName" label="Nome da sessão" placeholder="principal" />
                        @error('sessionName')
                            <p class="mt-2 text-xs text-danger">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                <div class="mt-4 flex items-center gap-2">
                    <x-ui.button variant="primary" wire:click="connect" wire:loading.attr="disabled">
                        {{ $channel ? 'Conectar' : 'Criar e gerar QR' }}
                    </x-ui.button>
                    <x-ui.button variant="secondary" wire:click="disconnect" wire:loading.attr="disabled">
                        Desconectar
                    </x-ui.button>
                </div>
            </div>

            <div class="rounded-[14px] border border-line bg-surface p-5">
                <p class="text-sm font-semibold text-ink">Importar histórico</p>

                <div class="mt-3 flex flex-wrap items-center gap-4">
                    <flux:checkbox wire:model="backfill" label="Importar histórico" />
                    <flux:input
                        wire:model="backfillDays"
                        type="number"
                        min="1"
                        max="365"
                        class="max-w-24"
                        :disabled="! $backfill"
                    />
                    <span class="text-xs text-ink-3">dias</span>
                </div>

                @error('backfillDays')
                    <p class="mt-2 text-xs text-danger">{{ $message }}</p>
                @enderror

                <p class="mt-2 text-xs leading-relaxed text-ink-3">
                    Desmarcado por padrão. A importação roda depois da conexão e não bloqueia o atendimento.
                </p>
            </div>

            @if ($status === \App\Enums\ChannelStatus::SCAN_QR_CODE)
                <div class="rounded-[14px] border border-line bg-surface p-5">
                    <p class="text-sm font-semibold text-ink">Conectar o número</p>

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

                    <div class="mt-4">
                        @if ($qr)
                            <img src="{{ $qr }}" alt="QR do WhatsApp" class="size-56 rounded-[10px] border border-line bg-white p-2">
                            <p class="mt-2 text-xs text-ink-3">O QR é renovado automaticamente.</p>
                        @else
                            <p class="text-xs text-ink-3">Aguardando o QR do WAHA…</p>
                        @endif
                    </div>
                </div>
            @endif

            @if ($errorMessage)
                <p class="rounded-[10px] border border-danger/30 bg-danger/5 px-3 py-2 text-xs text-danger">
                    {{ $errorMessage }}
                </p>
            @endif

            @if ($channel?->status === \App\Enums\ChannelStatus::WORKING)
                <div class="rounded-[14px] border border-line bg-surface p-5">
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
                </div>
            @endif

            <div class="rounded-[14px] border border-line bg-surface p-5">
                <p class="text-sm font-semibold text-ink">Últimos eventos</p>

                @if ($lastEvents === [])
                    <p class="mt-2 text-xs text-ink-3">Nenhum evento recebido ainda.</p>
                @else
                    <table class="mt-3 w-full text-left text-xs">
                        <thead class="text-ink-3">
                            <tr>
                                <th class="pb-1 font-medium">Evento</th>
                                <th class="pb-1 font-medium">Sessão</th>
                                <th class="pb-1 font-medium">Hora</th>
                            </tr>
                        </thead>
                        <tbody class="font-mono text-ink-2">
                            @foreach ($lastEvents as $event)
                                <tr class="border-t border-line">
                                    <td class="py-1.5">{{ $event['event'] }}</td>
                                    <td class="py-1.5">{{ $event['session'] ?? '—' }}</td>
                                    <td class="py-1.5">{{ $event['at'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                <p class="mt-3 text-xs text-warn">
                    Se este número cair, o atendimento não migra sozinho para o outro: ambos precisam ser
                    reconectados aqui.
                </p>
            </div>
        </div>
    </x-pages::settings.layout>
</section>
