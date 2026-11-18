<x-settings-page route="configuracoes.canais" title="Canais" subtitle="Conexão do WhatsApp" width="wide">
    <div class="mt-6 space-y-5">
        <div class="flex gap-3 rounded-xl border border-warn-line bg-warn-soft px-4 py-3 text-warn" role="note">
            <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0" />
            <div class="text-xs leading-relaxed">
                <p class="text-sm font-semibold">Sem failover entre números</p>
                <p>Se um número cair, o outro não assume a fila. Um alerta vai para supervisor e admin, e a equipe atende pelos dois inboxes.</p>
            </div>
        </div>

        <div class="grid items-start gap-5 xl:grid-cols-2">
            @foreach ($channels as $channel)
                <livewire:settings.channel-card :channel="$channel" :key="'channel-'.$channel->id" />
            @endforeach
        </div>

        <x-panel title="Adicionar número">
            <div class="p-4">
                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <flux:input wire:model="sessionName" label="Nome da sessão" placeholder="principal" />
                        @error('sessionName')
                            <p class="mt-2 text-xs text-danger">{{ $message }}</p>
                        @enderror
                    </div>
                    <flux:button size="sm" variant="primary" wire:click="addChannel" wire:loading.attr="disabled">
                        Adicionar
                    </flux:button>
                </div>

                <p class="mt-2 text-xs leading-relaxed text-ink-3">
                    Cada número tem sua sessão no WAHA. Depois de adicionar, use Conectar no cartão do número.
                    Para voltar um número que você excluiu, use o mesmo nome de sessão: o canal e os tickets dele são reaproveitados.
                    Outro nome cria um canal novo, sem o histórico.
                </p>
            </div>
        </x-panel>

        <x-panel title="Webhook de entrada">
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                <p class="min-w-0 break-all font-mono text-xs text-ink-2">{{ $webhookUrl }}</p>
                <x-status-badge :tone="$receiving ? 'ok' : 'neutral'">{{ $receiving ? 'recebendo' : 'sem eventos recentes' }}</x-status-badge>
            </div>
        </x-panel>

        <x-panel title="Últimos eventos">
            @if ($lastEvents === [])
                <p class="px-4 py-3 text-xs text-ink-3">Nenhum evento recebido ainda.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-canvas text-ink-3">
                            <tr>
                                <th class="px-4 py-2 font-semibold">Evento</th>
                                <th class="px-4 py-2 font-semibold">Sessão</th>
                                <th class="px-4 py-2 font-semibold">Hora</th>
                            </tr>
                        </thead>
                        <tbody class="font-mono text-ink-2">
                            @foreach ($lastEvents as $event)
                                <tr class="border-t border-line">
                                    <td class="px-4 py-2">{{ $event['event'] }}</td>
                                    <td class="px-4 py-2">{{ $event['session'] ?? '—' }}</td>
                                    <td class="px-4 py-2 whitespace-nowrap">{{ $event['at'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-panel>
    </div>
</x-settings-page>
