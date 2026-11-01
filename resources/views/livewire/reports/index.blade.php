<section class="mx-auto w-full max-w-6xl space-y-6 p-6">
    <x-page-header title="Relatórios" :subtitle="'Atendimento e SLA de primeira resposta ('.$sla.' min), a partir de '.$from->format('d/m/Y').'.'">
        <flux:radio.group wire:model.live="period" variant="segmented" aria-label="Período">
            @foreach (\App\Livewire\Reports\Index::PERIODS as $value => $label)
                <flux:radio :value="$value" :label="$label" />
            @endforeach
        </flux:radio.group>
    </x-page-header>

    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-[14px] border border-line bg-surface p-4">
            <dt class="text-xs text-ink-3">Tickets abertos</dt>
            <dd class="mt-1 font-mono text-2xl text-ink">{{ number_format($data['opened'], 0, ',', '.') }}</dd>
        </div>
        <div class="rounded-[14px] border border-line bg-surface p-4">
            <dt class="text-xs text-ink-3">1ª resposta (mediana)</dt>
            <dd class="mt-1 font-mono text-2xl text-ink">{{ \App\Livewire\Reports\Index::duration($data['median_seconds']) }}</dd>
        </div>
        <div class="rounded-[14px] border border-line bg-surface p-4">
            <dt class="text-xs text-ink-3">SLA cumprido</dt>
            <dd class="mt-1 font-mono text-2xl text-ink">{{ $data['sla_percent'] === null ? '—' : number_format($data['sla_percent'], 1, ',', '.').'%' }}</dd>
        </div>
        <div class="rounded-[14px] border border-line bg-surface p-4">
            <dt class="text-xs text-ink-3">Sem resposta além do SLA</dt>
            <dd class="mt-1 font-mono text-2xl {{ $data['overdue'] > 0 ? 'text-danger' : 'text-ink' }}">{{ $data['overdue'] }}</dd>
            <p class="text-xs text-ink-3">tickets vencidos agora</p>
        </div>
    </dl>

    @if ($schedulerStale)
        <div class="rounded-[14px] border border-warn/30 bg-warn/10 p-4 text-sm text-warn" role="alert">
            O verificador de SLA não roda há mais de 3 minutos. Os alertas de espera podem estar atrasados.
        </div>
    @endif

    <div class="rounded-[14px] border border-line bg-surface p-4">
        <h2 class="text-sm font-semibold text-ink">Aguardando resposta além do SLA</h2>
        <p class="text-xs text-ink-3">Clientes esperando resposta humana, perto de estourar ou já estourados (só conta o horário de funcionamento).</p>

        @if ($data['waiting'] === [])
            <p class="mt-3 text-sm text-ink-3">Ninguém esperando além do SLA agora.</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-ink-3">
                        <tr>
                            <th scope="col" class="py-2 pe-4 text-start font-normal">Cliente</th>
                            <th scope="col" class="px-4 py-2 text-start font-normal">Setor</th>
                            <th scope="col" class="px-4 py-2 text-start font-normal">Responsável</th>
                            <th scope="col" class="px-4 py-2 text-start font-normal">Esperando desde</th>
                            <th scope="col" class="px-4 py-2 text-start font-normal">Limite</th>
                            <th scope="col" class="px-4 py-2 text-start font-normal">Situação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($data['waiting'] as $row)
                            <tr>
                                <td class="py-2 pe-4 text-ink"><a href="{{ route('inbox') }}" wire:navigate class="underline-offset-2 hover:underline">{{ $row['contact'] }}</a></td>
                                <td class="px-4 py-2 text-ink-2">{{ $row['sector'] ?? 'Fila geral' }}</td>
                                <td class="px-4 py-2 text-ink-2">{{ $row['agent'] ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono text-ink-2">{{ $row['since']->timezone(config('app.display_timezone'))->format('d/m H:i') }}</td>
                                <td class="px-4 py-2 font-mono text-ink-2">{{ $row['due']->timezone(config('app.display_timezone'))->format('d/m H:i') }}</td>
                                <td class="px-4 py-2">
                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-medium {{ $row['state'] === 'estourou' ? 'bg-danger/10 text-danger' : 'bg-warn/10 text-warn' }}">{{ $row['state'] === 'estourou' ? 'SLA estourado' : 'Perto de estourar' }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="rounded-[14px] border border-line bg-surface p-4">
        <h2 class="text-sm font-semibold text-ink">Volume por setor</h2>
        @php($max = max(1, collect($data['by_sector'])->max('count') ?? 1))
        <ul class="mt-3 space-y-2">
            @forelse ($data['by_sector'] as $row)
                <li class="grid grid-cols-[8rem_1fr_3rem] items-center gap-3 text-sm">
                    <span class="truncate text-ink-2">{{ $row['name'] }}</span>
                    <span class="h-2 rounded bg-surface-2"><span class="block h-2 rounded bg-accent" style="width: {{ round($row['count'] / $max * 100) }}%"></span></span>
                    <span class="text-end font-mono text-ink">{{ $row['count'] }}</span>
                </li>
            @empty
                <li class="text-sm text-ink-3">Nenhum ticket no período.</li>
            @endforelse
        </ul>
    </div>

    <div class="overflow-x-auto rounded-[14px] border border-line bg-surface">
        <table class="w-full text-start text-sm">
            <caption class="px-4 pt-4 text-start text-sm font-semibold text-ink">Desempenho por atendente</caption>
            <thead class="text-xs text-ink-3">
                <tr>
                    <th scope="col" class="px-4 py-2 text-start font-normal">Atendente</th>
                    <th scope="col" class="px-4 py-2 text-end font-normal">Assumidos</th>
                    <th scope="col" class="px-4 py-2 text-end font-normal">1ª resposta (mediana)</th>
                    <th scope="col" class="px-4 py-2 text-end font-normal">Encerrados</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($data['by_agent'] as $row)
                    <tr>
                        <td class="px-4 py-2 text-ink">{{ $row['name'] }}</td>
                        <td class="px-4 py-2 text-end font-mono">{{ $row['assigned'] }}</td>
                        <td class="px-4 py-2 text-end font-mono">{{ \App\Livewire\Reports\Index::duration($row['median_seconds']) }}</td>
                        <td class="px-4 py-2 text-end font-mono">{{ $row['closed'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-4 text-center text-ink-3">Nenhum atendimento no período.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
