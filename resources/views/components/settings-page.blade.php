@props(['route', 'title', 'subtitle' => null, 'groups' => null, 'label' => 'Configuração', 'width' => 'form'])

{{-- A rota vem do chamador: numa ação do Livewire a requisição é `livewire/update`, e o nome da rota atual não serve.
     Abas de lista usam `width="wide"` (largura toda); as de formulário param em max-w-4xl. --}}
@php
    $groups ??= app(\App\Support\Navigation::class)->settingsGroups(auth()->user());
@endphp

<div class="mx-auto flex w-full max-w-[120rem] flex-col gap-4 p-6 lg:flex-row lg:gap-8">
    @if ($groups !== [])
        {{-- O título do grupo é nosso: o heading do flux:navlist.group pinta zinc. Item com 40 px em toda largura. --}}
        <flux:navlist :aria-label="$label" class="shrink-0 lg:w-56">
            <div x-data="currentInView" class="flex gap-6 overflow-x-auto lg:flex-col lg:gap-5 lg:overflow-visible">
                @foreach ($groups as $group => $items)
                    <div class="shrink-0">
                        <p data-test="settings-group-title" class="px-3 pb-1 text-xs font-semibold text-ink-3">{{ $group }}</p>
                        <ul class="flex gap-1 lg:flex-col">
                            @foreach ($items as $item)
                                <li>
                                    <flux:navlist.item
                                        :href="route($item->route)"
                                        :icon="$item->icon"
                                        :current="$item->route === $route"
                                        :aria-current="$item->route === $route ? 'page' : null"
                                        wire:navigate
                                        class="my-0! h-10! gap-2! text-ink-2! transition-colors duration-(--duration-base) ease-(--ease-out) not-data-current:hover:bg-canvas! not-data-current:hover:text-ink! data-current:bg-accent-soft! data-current:text-accent-content!"
                                    >{{ $item->label }}</flux:navlist.item>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </flux:navlist>
    @endif

    <section @class(['w-full min-w-0 flex-1', 'max-w-4xl' => $width !== 'wide'])>
        <x-page-header :title="$title" :subtitle="$subtitle">@isset($actions){{ $actions }}@endisset</x-page-header>

        {{ $slot }}
    </section>
</div>
