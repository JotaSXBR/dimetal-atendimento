<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('inbox') }}" wire:navigate />
                <livewire:alerts.bell />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            @php($counts = app(\App\Support\SidebarState::class)->counts(auth()->user()))
            @php($nav = app(\App\Support\Navigation::class))
            @php($entry = $nav->settingsEntry(auth()->user()))

            <flux:sidebar.nav>
                @foreach ($nav->primary(auth()->user()) as $item)
                    <flux:sidebar.item :icon="$item->icon" :href="route($item->route)" :current="request()->routeIs($item->match)" wire:navigate
                        :badge="$item->route === 'inbox' ? ($counts['waiting'] ?: null) : null" badge-color="yellow"
                        badge:data-test="queue-badge" badge:aria-label="{{ $counts['waiting'] }} aguardando">
                        @if ($item->route === 'inbox' && $counts['breached'] > 0)
                            <x-slot:icon-trailing>
                                <span data-test="sla-badge" aria-label="{{ $counts['breached'] }} com SLA estourado" class="rounded-full bg-danger px-1.5 text-xs font-semibold text-surface">{{ $counts['breached'] }}</span>
                            </x-slot:icon-trailing>
                        @endif
                        {{ $item->label }}
                    </flux:sidebar.item>
                @endforeach

                @if ($entry !== null)
                    <flux:sidebar.item icon="cog-6-tooth" :href="route($entry->route)" :current="$nav->settingsActive(auth()->user())" class="mt-3" data-test="settings-entry" wire:navigate>
                        Configuração
                    </flux:sidebar.item>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            @if (app(\App\Support\SidebarState::class)->whatsappDown())
                <p class="mx-2 mb-2 rounded-md bg-warn/10 px-3 py-2 text-xs text-warn" role="status">WhatsApp desconectado. As mensagens ficam aguardando até a conexão voltar.</p>
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
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

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
