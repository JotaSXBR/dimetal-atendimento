@props(['label', 'value', 'note' => null, 'tone' => null])

@php
    $valueTone = match ($tone) {
        null => 'text-ink',
        'danger' => 'text-danger',
        default => throw new \InvalidArgumentException('x-stat: tom desconhecido "'.$tone.'".'),
    };
@endphp

{{-- Número de resumo (rótulo, valor e nota) dentro de um `dl`. Próprio porque o `flux:stat` é do Flux Pro; imita o desenho dele. --}}
<div data-stat {{ $attributes->class('rounded-xl border border-line bg-surface p-4') }}>
    <dt class="text-xs text-ink-3">{{ $label }}</dt>
    <dd class="mt-1">
        <span data-stat-value class="font-mono text-2xl font-medium {{ $valueTone }}">{{ $value }}</span>
        @if ($note)
            <span data-stat-note class="block text-xs text-ink-3">{{ $note }}</span>
        @endif
    </dd>
</div>
