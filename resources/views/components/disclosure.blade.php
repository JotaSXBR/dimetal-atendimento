@props(['title', 'open' => true, 'testid' => null])

{{-- Bloco recolhível (visual do accordion do Flux, que é Pro). `wire:ignore.self`: o re-render do Livewire não desfaz o que o usuário abriu ou fechou. --}}
<details wire:ignore.self @if ($testid) data-testid="{{ $testid }}" @endif @if ($open) open @endif {{ $attributes->class('group border-t border-line py-1 first:border-t-0') }}>
    <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-2 font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase [&::-webkit-details-marker]:hidden">
        <span>{{ $title }}</span>
        <flux:icon.chevron-down variant="micro" class="transition-transform duration-200 group-open:rotate-180 motion-reduce:transition-none" />
    </summary>
    <div class="pb-3">
        {{ $slot }}
    </div>
</details>
