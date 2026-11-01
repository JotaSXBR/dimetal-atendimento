<x-settings-page route="configuracoes.canais" title="Canais" subtitle="Conexão do WhatsApp" width="5xl">
    <div class="mt-6">
        <div class="space-y-6">
            <div class="flex gap-3 rounded-[14px] border border-warn/30 bg-warn/10 px-4 py-3 text-warn" role="note">
                <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0" />
                <div class="text-xs leading-relaxed">
                    <p class="text-sm font-semibold">Sem failover entre números</p>
                    <p>Se um número cair, o outro não assume a fila. Um alerta vai para supervisor e admin, e a equipe atende pelos dois inboxes.</p>
                </div>
            </div>

            <div class="grid items-start gap-6 xl:grid-cols-2">
                @foreach ($channels as $channel)
                    <livewire:settings.channel-card :channel="$channel" :key="'channel-'.$channel->id" />
                @endforeach
            </div>

            <div class="rounded-[14px] border border-line bg-surface p-5">
                <p class="text-sm font-semibold text-ink">Adicionar número</p>

                <div class="mt-3 flex items-end gap-2">
                    <div class="flex-1">
                        <flux:input wire:model="sessionName" label="Nome da sessão" placeholder="principal" />
                        @error('sessionName')
                            <p class="mt-2 text-xs text-danger">{{ $message }}</p>
                        @enderror
                    </div>
                    <x-ui.button variant="primary" wire:click="addChannel" wire:loading.attr="disabled">
                        Adicionar
                    </x-ui.button>
                </div>

                <p class="mt-2 text-xs leading-relaxed text-ink-3">
                    Cada número tem sua sessão no WAHA. Depois de adicionar, use Conectar no cartão do número.
                    Para voltar um número que você excluiu, use o mesmo nome de sessão: o canal e os tickets dele são reaproveitados.
                    Outro nome cria um canal novo, sem o histórico.
                </p>
            </div>

            <div class="rounded-[14px] border border-line bg-surface p-5">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-semibold text-ink">Webhook de entrada</p>
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium {{ $receiving ? 'bg-accent-soft text-accent-deep' : 'bg-canvas text-ink-2' }}">
                        <span class="size-1.5 rounded-full {{ $receiving ? 'bg-accent' : 'bg-ink-3' }}"></span>
                        {{ $receiving ? 'recebendo' : 'sem eventos recentes' }}
                    </span>
                </div>
                <p class="mt-2 break-all font-mono text-xs text-ink-2">{{ $webhookUrl }}</p>
            </div>

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
            </div>
        </div>
    </div>
</x-settings-page>
