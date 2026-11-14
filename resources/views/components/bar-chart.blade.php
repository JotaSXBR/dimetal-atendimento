@props(['rows', 'caption'])

@php
    // Gráfico de barras horizontais próprio (SVG, sem biblioteca; visual inspirado nos gráficos do Flux Pro, código próprio). Rótulo e número ficam em
    // HTML, e só a barra em SVG, para o texto nunca encolher abaixo de 12 px. A dica (`<title>`) traz o número exato e a fatia do total; o desenho
    // fica fora do leitor de tela, que lê a tabela equivalente.
    $max = max(1, (int) collect($rows)->max('value'));
    $total = (int) collect($rows)->sum('value');
@endphp

<div {{ $attributes }}>
    <ul class="space-y-2" aria-hidden="true">
        @foreach ($rows as $row)
            @php($share = $total > 0 ? (int) round($row['value'] / $total * 100) : 0)
            <li data-testid="bar-row" class="grid grid-cols-[8rem_1fr_3rem] items-center gap-3 text-sm">
                <span class="truncate text-ink-2" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                <svg class="h-2 w-full" viewBox="0 0 100 8" preserveAspectRatio="none" role="presentation">
                    <title>{{ $row['label'] }}: {{ $row['value'] }} ({{ $share }}%)</title>
                    <rect width="100" height="8" rx="2" class="fill-line" />
                    <rect width="{{ $row['value'] > 0 ? max(1, round($row['value'] / $max * 100)) : 0 }}" height="8" rx="2" class="fill-accent" />
                </svg>
                <span class="text-end font-mono text-ink">{{ $row['value'] }}</span>
            </li>
        @endforeach
    </ul>

    <table class="sr-only">
        <caption>{{ $caption }}</caption>
        <thead><tr><th scope="col">Nome</th><th scope="col">Quantidade</th></tr></thead>
        <tbody>
            @foreach ($rows as $row)
                <tr><th scope="row">{{ $row['label'] }}</th><td>{{ $row['value'] }}</td></tr>
            @endforeach
        </tbody>
    </table>
</div>
