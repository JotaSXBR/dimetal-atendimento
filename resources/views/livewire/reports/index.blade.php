<section class="mx-auto w-full max-w-[120rem] space-y-6 p-6">
    <x-page-header title="Relatórios" :subtitle="'Atendimento e SLA de primeira resposta ('.$sla.' min), a partir de '.$from->format('d/m/Y').'.'" />

    <flux:radio.group wire:model.live="period" variant="segmented" aria-label="Período" class="w-fit">
        @foreach (\App\Livewire\Reports\Index::PERIODS as $value => $label)
            <flux:radio :value="$value" :label="$label" />
        @endforeach
    </flux:radio.group>

    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Tickets abertos" :value="number_format($data['opened'], 0, ',', '.')" />
        <x-stat label="1ª resposta (mediana)" :value="\App\Livewire\Reports\Index::duration($data['median_seconds'])" />
        <x-stat label="SLA cumprido" :value="$data['sla_percent'] === null ? '—' : number_format($data['sla_percent'], 1, ',', '.').'%'" />
        <x-stat label="Sem resposta além do SLA" :value="$data['overdue']" note="tickets vencidos agora" :tone="$data['overdue'] > 0 ? 'danger' : null" />
    </dl>

    @if ($schedulerStale)
        <div class="rounded-xl border border-warn-line bg-warn-soft p-4 text-sm text-warn" role="alert">
            O verificador de SLA não roda há mais de 3 minutos. Os alertas de espera podem estar atrasados.
        </div>
    @endif

    {{-- Células com `px-4!`: o Flux zera o padding da primeira e da última coluna, e aqui a tabela encosta nas bordas do painel. --}}
    <div data-panel class="overflow-hidden rounded-xl border border-line bg-surface">
        <div class="border-b border-line px-4 py-3.5">
            <h2 class="text-sm font-semibold text-ink">Aguardando resposta além do SLA</h2>
            <p class="text-xs text-ink-3">Clientes esperando resposta humana, perto de estourar ou já estourados (só conta o horário de funcionamento).</p>
        </div>

        @if ($data['waiting'] === [])
            <p class="p-4 text-sm text-ink-3">Ninguém esperando além do SLA agora.</p>
        @else
            <div class="overflow-x-auto">
                <flux:table class="w-full min-w-[52rem]">
                    <caption class="sr-only">Aguardando resposta além do SLA</caption>
                    <flux:table.columns class="bg-canvas">
                        <flux:table.column class="px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Cliente</span></flux:table.column>
                        <flux:table.column class="w-40 px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Setor</span></flux:table.column>
                        <flux:table.column class="w-44 px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Responsável</span></flux:table.column>
                        <flux:table.column class="w-36 px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Esperando desde</span></flux:table.column>
                        <flux:table.column class="w-32 px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Limite</span></flux:table.column>
                        <flux:table.column class="w-40 px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Situação</span></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($data['waiting'] as $row)
                            <flux:table.row>
                                <flux:table.cell class="px-4! py-[11px] border-line!"><a href="{{ route('inbox') }}" wire:navigate class="block min-h-6 truncate text-sm leading-6 text-ink underline-offset-2 hover:underline" title="{{ $row['contact'] }}">{{ $row['contact'] }}</a></flux:table.cell>
                                <flux:table.cell class="px-4! py-[11px] border-line!"><span class="block truncate text-sm text-ink-2">{{ $row['sector'] ?? 'Fila geral' }}</span></flux:table.cell>
                                <flux:table.cell class="px-4! py-[11px] border-line!"><span class="block truncate text-sm text-ink-2">{{ $row['agent'] ?? '—' }}</span></flux:table.cell>
                                <flux:table.cell class="px-4! py-[11px] border-line!"><span class="font-mono text-sm text-ink-2">{{ $row['since']->timezone(config('app.display_timezone'))->format('d/m H:i') }}</span></flux:table.cell>
                                <flux:table.cell class="px-4! py-[11px] border-line!"><span class="font-mono text-sm text-ink-2">{{ $row['due']->timezone(config('app.display_timezone'))->format('d/m H:i') }}</span></flux:table.cell>
                                <flux:table.cell class="px-4! py-[11px] border-line!">
                                    <x-status-badge :tone="$row['state'] === 'estourou' ? 'danger' : 'warn'">{{ $row['state'] === 'estourou' ? 'SLA estourado' : 'Perto de estourar' }}</x-status-badge>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </div>

    <div data-panel class="overflow-hidden rounded-xl border border-line bg-surface">
        <div class="border-b border-line px-4 py-3.5">
            <h2 class="text-sm font-semibold text-ink">Volume por setor</h2>
        </div>
        <div class="p-4">
            @if ($data['by_sector'] === [])
                <x-empty-state compact icon="chart-bar" title="Nenhum ticket no período." />
            @else
                <x-bar-chart caption="Volume por setor" :rows="collect($data['by_sector'])->map(fn ($row) => ['label' => $row['name'], 'value' => $row['count']])->all()" />
            @endif
        </div>
    </div>

    <div data-panel class="overflow-hidden rounded-xl border border-line bg-surface">
        <div class="border-b border-line px-4 py-3.5">
            <h2 class="text-sm font-semibold text-ink">Desempenho por atendente</h2>
            <p class="text-xs text-ink-3">Assumidos, primeira resposta e encerrados no período.</p>
        </div>
        <div class="overflow-x-auto">
            <flux:table class="w-full min-w-[40rem]">
                <caption class="sr-only">Desempenho por atendente</caption>
                <flux:table.columns class="bg-canvas">
                    <flux:table.column class="px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Atendente</span></flux:table.column>
                    <flux:table.column align="end" class="w-28 px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Assumidos</span></flux:table.column>
                    <flux:table.column align="end" class="w-44 px-4! border-line!"><span class="text-xs font-semibold text-ink-3">1ª resposta (mediana)</span></flux:table.column>
                    <flux:table.column align="end" class="w-28 px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Encerrados</span></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($data['by_agent'] as $row)
                        <flux:table.row>
                            <flux:table.cell class="px-4! py-[11px] border-line!">
                                <span class="flex min-w-0 items-center gap-2">
                                    <x-avatar :name="$row['name']" size="xs" decorative />
                                    <span class="truncate text-sm text-ink" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                                </span>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="px-4! py-[11px] border-line!"><span class="font-mono text-sm text-ink">{{ $row['assigned'] }}</span></flux:table.cell>
                            <flux:table.cell align="end" class="px-4! py-[11px] border-line!"><span class="font-mono text-sm text-ink">{{ \App\Livewire\Reports\Index::duration($row['median_seconds']) }}</span></flux:table.cell>
                            <flux:table.cell align="end" class="px-4! py-[11px] border-line!"><span class="font-mono text-sm text-ink">{{ $row['closed'] }}</span></flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="whitespace-normal border-line!">
                                <x-empty-state compact icon="chart-bar" title="Nenhum atendimento no período." />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </div>
</section>
