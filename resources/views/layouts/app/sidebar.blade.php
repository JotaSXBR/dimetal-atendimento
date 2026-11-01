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
            @php($tree = app(\App\Support\Navigation::class)->tree(auth()->user()))

            <flux:sidebar.nav>
                @foreach ($tree as $section => $groups)
                    @if ($section === \App\Support\Navigation::SECTION_CONFIGURACAO)
                        <p class="px-3 pt-2 font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">{{ $section }}</p>
                    @endif

                    @foreach ($groups as $group => $items)
                        <flux:sidebar.group :heading="$group !== '' ? $group : $section" class="grid">
                            @foreach ($items as $item)
                                <flux:sidebar.item :icon="$item->icon" :href="route($item->route)" :current="request()->routeIs($item->match)" :badge="$item->route === 'inbox' ? ($counts['waiting'] ?: null) : null" wire:navigate>
                                    {{ $item->label }}
                                </flux:sidebar.item>

                                @if ($item->route === 'inbox' && ($counts['waiting'] > 0 || $counts['breached'] > 0))
                                    <p class="-mt-1 mb-1 ps-9 text-xs text-ink-3">
                                        {{ $counts['waiting'] }} aguardando
                                        @if ($counts['breached'] > 0)
                                            <span class="block font-medium text-danger">{{ $counts['breached'] }} com SLA estourado</span>
                                        @endif
                                    </p>
                                @endif
                            @endforeach
                        </flux:sidebar.group>
                    @endforeach
                @endforeach
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
