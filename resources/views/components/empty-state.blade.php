@props(['icon' => 'inbox', 'title', 'description' => null, 'compact' => false, 'action' => null])

<div data-empty-state {{ $attributes->class(['flex flex-col items-center gap-2 px-6 text-center', $compact ? 'py-4' : 'py-10']) }}>
    <flux:icon :name="$icon" class="size-6 text-ink-3" />
    <p class="text-sm font-medium text-ink">{{ $title }}</p>
    @if ($description)
        <p class="max-w-xs text-xs text-ink-2">{{ $description }}</p>
    @endif
    @if ($action && ! $action->isEmpty())
        <div data-empty-state-action class="mt-1">{{ $action }}</div>
    @endif
</div>
