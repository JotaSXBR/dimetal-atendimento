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

            <flux:sidebar.nav>
                <flux:sidebar.group heading="Atendimento" class="grid">
                    <flux:sidebar.item icon="inbox" :href="route('inbox')" :current="request()->routeIs('inbox')" :badge="$counts['waiting'] ?: null" wire:navigate>
                        Atendimento
                    </flux:sidebar.item>

                    @if ($counts['waiting'] > 0 || $counts['breached'] > 0)
                        <p class="-mt-1 mb-1 ps-9 text-xs text-ink-3">
                            {{ $counts['waiting'] }} aguardando
                            @if ($counts['breached'] > 0)
                                <span class="block font-medium text-danger">{{ $counts['breached'] }} com SLA estourado</span>
                            @endif
                        </p>
                    @endif

                    <flux:sidebar.item icon="user-group" :href="route('contatos')" :current="request()->routeIs('contatos')" wire:navigate>
                        Contatos
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="archive-box" :href="route('historico')" :current="request()->routeIs('historico')" wire:navigate>
                        Histórico
                    </flux:sidebar.item>
                </flux:sidebar.group>

                @can('supervisor')
                    <flux:sidebar.group heading="Gestão" class="grid">
                        <flux:sidebar.item icon="chart-bar" :href="route('relatorios')" :current="request()->routeIs('relatorios')" wire:navigate>
                            Relatórios
                        </flux:sidebar.item>

                        @can('admin')
                            <flux:sidebar.item icon="shield-check" :href="route('auditoria')" :current="request()->routeIs('auditoria')" wire:navigate>
                                Auditoria
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>

                    <p class="px-3 pt-2 font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Configurações</p>

                    <flux:sidebar.group heading="Operação" class="grid">
                        @can('admin')
                            <flux:sidebar.item icon="chat-bubble-left-right" :href="route('configuracoes.canais')" :current="request()->routeIs('configuracoes.canais')" wire:navigate>
                                Canais
                            </flux:sidebar.item>

                            <flux:sidebar.item icon="squares-2x2" :href="route('configuracoes.setores')" :current="request()->routeIs('configuracoes.setores')" wire:navigate>
                                Setores
                            </flux:sidebar.item>

                            <flux:sidebar.item icon="clock" :href="route('configuracoes.horario')" :current="request()->routeIs('configuracoes.horario')" wire:navigate>
                                Horário
                            </flux:sidebar.item>
                        @endcan

                        <flux:sidebar.item icon="bolt" :href="route('configuracoes.respostas')" :current="request()->routeIs('configuracoes.respostas')" wire:navigate>
                            Respostas rápidas
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="Pessoas e empresa" class="grid">
                        <flux:sidebar.item icon="users" :href="route('configuracoes.usuarios')" :current="request()->routeIs('configuracoes.usuarios')" wire:navigate>
                            Usuários
                        </flux:sidebar.item>

                        @can('admin')
                            <flux:sidebar.item icon="building-office" :href="route('configuracoes.empresa')" :current="request()->routeIs('configuracoes.empresa')" wire:navigate>
                                Empresa
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>

                    @can('admin')
                        <flux:sidebar.group heading="Dados e integrações" class="grid">
                            <flux:sidebar.item icon="link" :href="route('configuracoes.google')" :current="request()->routeIs('configuracoes.google*')" wire:navigate>
                                Google Contatos
                            </flux:sidebar.item>

                            <flux:sidebar.item icon="trash" :href="route('configuracoes.retencao')" :current="request()->routeIs('configuracoes.retencao')" wire:navigate>
                                Retenção
                            </flux:sidebar.item>
                        </flux:sidebar.group>
                    @endcan
                @endcan
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
                            {{ __('Settings') }}
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
                            {{ __('Log out') }}
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
