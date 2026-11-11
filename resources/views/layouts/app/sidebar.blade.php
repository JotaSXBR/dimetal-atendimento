<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        {{-- --sidebar-bg: fundo da sidebar, também usado no anel do ponto da Fila (uma só fonte para as duas cores). --}}
        <flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-(--sidebar-bg) [--sidebar-bg:var(--color-zinc-50)] dark:border-zinc-700 dark:[--sidebar-bg:var(--color-zinc-900)]">
            <flux:sidebar.header class="in-data-flux-sidebar-collapsed-desktop:flex-col in-data-flux-sidebar-collapsed-desktop:items-center in-data-flux-sidebar-collapsed-desktop:gap-3">
                {{-- Fechada, o Flux tira a marca do fluxo no hover e só então mostra o botão de colapso: sem mouse (toque, teclado) não haveria como
                     reabrir, e o sino pulava de lugar. Aqui a marca fica fixa e o botão fica sempre à vista. A ordem do DOM é a ordem visual nos dois
                     estados (marca, botão, sino): o Tab segue o que se vê. --}}
                <x-app-logo :sidebar="true" href="{{ route('inbox') }}" aria-label="{{ \App\Settings\CompanySettings::logoUrl() === null ? mb_strtoupper(mb_substr(trim(\App\Settings\CompanySettings::name()), 0, 1)).' ' : '' }}{{ trim(\App\Settings\CompanySettings::name()) }}" wire:navigate class="in-data-flux-sidebar-collapsed-desktop:in-data-flux-sidebar-active:static! in-data-flux-sidebar-collapsed-desktop:in-data-flux-sidebar-active:opacity-100!" />
                <flux:sidebar.collapse tooltip="Recolher ou expandir menu" class="in-data-flux-sidebar-collapsed-desktop:static! in-data-flux-sidebar-collapsed-desktop:opacity-100!" />
                <livewire:alerts.bell />
            </flux:sidebar.header>

            @php
                $counts = app(\App\Support\SidebarState::class)->counts(auth()->user());
                $nav = app(\App\Support\Navigation::class);
                $entry = $nav->settingsEntry(auth()->user());
            @endphp

            <flux:sidebar.nav>
                @foreach ($nav->primary(auth()->user()) as $item)
                    @php
                        // Fechada, o texto do item some e o `aria-label` vira o nome do link; ele substitui o conteúdo, então leva as contagens do selo.
                        $name = $item->route === 'inbox'
                            ? $item->label.($counts['waiting'] > 0 ? ', '.$counts['waiting'].' aguardando' : '').($counts['breached'] > 0 ? ', '.$counts['breached'].' com SLA estourado' : '')
                            : $item->label;
                    @endphp
                    <flux:sidebar.item :href="route($item->route)" :current="request()->routeIs($item->match)" aria-label="{{ $name }}" wire:navigate
                        :badge="$item->route === 'inbox' ? ($counts['waiting'] ?: null) : null" badge-color="yellow"
                        badge:data-test="queue-badge">
                        {{-- Fechada, o Flux esconde badge e selo: o estado da Fila vai num ponto sobre o ícone (vermelho só com SLA estourado). --}}
                        <x-slot:icon>
                            <flux:icon :icon="$item->icon" class="size-4" />
                            @if ($item->route === 'inbox' && ($counts['breached'] > 0 || $counts['waiting'] > 0))
                                <span data-test="queue-dot" aria-hidden="true" class="absolute -end-0.5 -top-0.5 hidden size-2.5 rounded-full ring-2 ring-(--sidebar-bg) in-data-flux-sidebar-collapsed-desktop:block {{ $counts['breached'] > 0 ? 'bg-danger' : 'bg-accent' }}"></span>
                            @endif
                        </x-slot:icon>
                        @if ($item->route === 'inbox' && $counts['breached'] > 0)
                            <x-slot:icon-trailing>
                                <span data-test="sla-badge" aria-label="{{ $counts['breached'] }} com SLA estourado" class="rounded-full bg-danger px-1.5 text-xs font-semibold text-surface in-data-flux-sidebar-collapsed-desktop:hidden">{{ $counts['breached'] }}</span>
                            </x-slot:icon-trailing>
                        @endif
                        {{ $item->label }}
                    </flux:sidebar.item>
                @endforeach

                @if ($entry !== null)
                    <flux:sidebar.item icon="cog-6-tooth" :href="route($entry->route)" :current="$nav->settingsActive(auth()->user())" class="mt-3" data-test="settings-entry" aria-label="Configuração" wire:navigate>
                        Configuração
                    </flux:sidebar.item>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            @if (app(\App\Support\SidebarState::class)->whatsappDown())
                {{-- Fechada, o texto some e fica só o ícone, com o mesmo aviso no rótulo e no title. --}}
                <div data-test="whatsapp-down" role="status" class="mx-2 mb-2 rounded-md bg-warn/10 in-data-flux-sidebar-collapsed-desktop:mx-0">
                    <p class="px-3 py-2 text-xs text-warn in-data-flux-sidebar-collapsed-desktop:hidden">WhatsApp desconectado. As mensagens ficam aguardando até a conexão voltar.</p>
                    <span role="img" aria-label="WhatsApp desconectado" title="WhatsApp desconectado. As mensagens ficam aguardando até a conexão voltar." class="hidden size-10 items-center justify-center text-warn in-data-flux-sidebar-collapsed-desktop:flex">
                        <flux:icon.exclamation-triangle class="size-5" />
                    </span>
                </div>
            @endif

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <x-avatar :src="auth()->user()->avatarUrl()" :name="auth()->user()->name" shape="round" decorative />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            Minha conta
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <x-appearance-switcher />

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            Sair
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
