@props(['icon' => 'inbox', 'title', 'description' => null, 'compact' => false, 'action' => null])

<div data-empty-state {{ $attributes->class(['flex flex-col items-center text-center', $compact ? 'gap-2 px-6 py-4' : 'gap-2.5 p-8']) }}>
    @if ($compact)
        <flux:icon :name="$icon" class="size-6 text-ink-3" />
    @else
        <div data-empty-state-icon class="flex size-11 items-center justify-center rounded-full bg-canvas">
            <flux:icon :name="$icon" class="size-5 text-ink-3" />
        </div>
    @endif
    <p class="text-sm font-semibold text-ink">{{ $title }}</p>
    @if ($description)
        {{-- ink-3 passa 4,5:1 sobre canvas e surface nos dois temas (ThemeTokensTest). --}}
        <p class="max-w-xs text-xs text-ink-3">{{ $description }}</p>
    @endif
    @if ($action && ! $action->isEmpty())
        <div data-empty-state-action class="mt-1">{{ $action }}</div>
    @endif
</div>
