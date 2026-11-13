@props(['status' => null, 'tone' => null])

@php
    // O tom vem do enum (`tone()`, fonte única) ou é passado à mão para o que não é enum (SLA, ativo/inativo...). O texto: o slot, senão o `label()` do enum.
    $tone ??= $status?->tone();

    if ($tone === null) {
        throw new \InvalidArgumentException('x-status-badge: informe `status` ou `tone`.');
    }

    $classes = match ($tone) {
        'neutral' => 'bg-canvas text-ink-2 ring-1 ring-line',
        'bot' => 'bg-bot/10 text-bot',
        'ok' => 'bg-ok/10 text-ok',
        'warn' => 'bg-warn-soft text-warn',
        'danger' => 'bg-danger/10 text-danger',
        'accent' => 'bg-accent-soft text-accent-content',
        default => throw new \InvalidArgumentException('x-status-badge: tom desconhecido "'.$tone.'".'),
    };
@endphp

{{-- Nunca só cor: o texto sempre existe; o ponto é decorativo. --}}
<span data-status-tone="{{ $tone }}" {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap', $classes]) }}>
    <span aria-hidden="true" class="size-1.5 shrink-0 rounded-full bg-current"></span>
    {{ $slot->isEmpty() ? $status?->label() : $slot }}
</span>
