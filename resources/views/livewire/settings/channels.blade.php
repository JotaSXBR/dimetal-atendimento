<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout heading="Canais" subheading="Conexão do WhatsApp">
        <div class="space-y-6">
            @foreach ($channels as $channel)
                <livewire:settings.channel-card :channel="$channel" :key="'channel-'.$channel->id" />
            @endforeach

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
                </p>
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

                <p class="mt-3 text-xs text-warn">
                    Se um número cair, o atendimento não migra sozinho para o outro: cada um precisa ser
                    reconectado aqui.
                </p>
            </div>
        </div>
    </x-pages::settings.layout>
</section>
