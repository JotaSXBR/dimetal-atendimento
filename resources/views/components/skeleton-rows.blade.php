@props(['rows' => 5])

{{-- Só aparece durante o carregamento (o chamador põe wire:loading.delay); escondido do leitor de tela. --}}
<flux:skeleton.group animate="pulse" data-skeleton aria-hidden="true" {{ $attributes->class('w-full space-y-3 p-3') }}>
    @for ($i = 0; $i < $rows; $i++)
        <div data-skeleton-row class="flex items-center gap-3">
            <flux:skeleton class="size-8 shrink-0 rounded-full" />
            <div class="flex-1 space-y-2">
                <flux:skeleton.line class="w-2/3" />
                <flux:skeleton.line class="w-1/3" />
            </div>
        </div>
    @endfor
</flux:skeleton.group>
