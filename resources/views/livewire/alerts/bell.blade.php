@php
    $tones = ['danger' => 'bg-danger/10 text-danger', 'warn' => 'bg-warn/10 text-warn', 'accent' => 'bg-accent/10 text-accent'];
@endphp

<div class="relative" x-data="{ open: false, x: 0, y: 0 }" @keydown.escape.window="open = false" @click.outside="open = false">
    {{-- O menu lateral rola (overflow) e cortaria o painel: ele é `fixed`, posicionado pelo botão. --}}
    <button type="button" @click="const r = $el.getBoundingClientRect(); x = r.left; y = r.bottom + 8; open = !open" :aria-expanded="open" aria-haspopup="true" aria-label="Alertas{{ $unread > 0 ? ', '.$unread.' não lidos' : '' }}"
        class="relative flex size-8 items-center justify-center rounded-lg bg-white/10 text-sb-ink-1 hover:bg-white/20">
        <flux:icon.bell variant="micro" class="size-4" />
        @if ($unread > 0)
            <span class="absolute -end-1 -top-1 flex min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] leading-4 font-bold text-white" aria-hidden="true">{{ $unread > 9 ? '9+' : $unread }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak x-transition.opacity :style="`left: ${x}px; top: ${y}px`" class="fixed z-50 w-96 max-w-[calc(100vw-1rem)] overflow-hidden rounded-xl border border-line bg-surface text-ink shadow-xl" role="dialog" aria-label="Alertas">
        <div class="flex items-center justify-between border-b border-line px-3.5 py-3">
            <p class="text-sm font-bold">Alertas <span class="ms-1 text-xs font-normal text-ink-3">{{ $unread }} {{ $unread === 1 ? 'novo' : 'novos' }}</span></p>
            @if ($unread > 0)
                <button type="button" wire:click="markAllRead" class="text-xs font-semibold text-accent hover:underline">Marcar todas como lidas</button>
            @endif
        </div>

        <ul class="max-h-96 divide-y divide-line overflow-y-auto">
            @forelse ($items as $item)
                <li wire:key="alert-{{ $item['id'] }}">
                    <button type="button" wire:click="markRead('{{ $item['id'] }}')" class="flex w-full items-start gap-2.5 px-3.5 py-2.5 text-start hover:bg-canvas">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full {{ $tones[$item['tone']] }}">
                            <flux:icon :name="$item['icon']" variant="micro" class="size-3.5" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[13px] font-semibold">{{ $item['title'] }}</span>
                            @if ($item['detail'] !== '')
                                <span class="block truncate text-xs text-ink-3">{{ $item['detail'] }}</span>
                            @endif
                            <span class="block text-[11px] text-ink-3">{{ $item['time'] }}</span>
                        </span>
                        @if ($item['unread'])
                            <span class="mt-1 size-2 shrink-0 rounded-full bg-accent"><span class="sr-only">não lido</span></span>
                        @endif
                    </button>
                </li>
            @empty
                <li class="px-3.5 py-6 text-center text-sm text-ink-3">Nenhum alerta nos últimos 7 dias.</li>
            @endforelse
        </ul>

        <p class="border-t border-line px-3.5 py-2.5 text-center text-[11px] text-ink-3">Os alertas ficam guardados por 7 dias</p>
    </div>
</div>
