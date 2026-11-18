@props(['label', 'value', 'note' => null, 'tone' => null])

{{-- Número de resumo (rótulo, valor e nota) dentro de um `dl`. Próprio porque o `flux:stat` é do Flux Pro; imita o desenho dele. --}}
<div data-stat {{ $attributes->class('rounded-xl border border-line bg-surface p-4') }}>
    <dt class="text-xs text-ink-3">{{ $label }}</dt>
    <dd class="mt-1">
        <span data-stat-value class="font-mono text-2xl font-medium {{ $tone === 'danger' ? 'text-danger' : 'text-ink' }}">{{ $value }}</span>
        @if ($note)
            <span data-stat-note class="block text-xs text-ink-3">{{ $note }}</span>
        @endif
    </dd>
</div>
