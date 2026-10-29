{{-- Cartão de localização: um mapa ilustrativo com um pino (sem carregar mapa de terceiros) que abre o Google Maps. --}}
<a
    data-location-card
    href="https://www.google.com/maps/search/?api=1&amp;query={{ urlencode($message->body) }}"
    target="_blank"
    rel="noopener"
    aria-label="Abrir localização no Google Maps"
    class="block w-64 max-w-full overflow-hidden rounded-lg border border-line bg-surface no-underline"
>
    <svg viewBox="0 0 256 112" class="block h-28 w-full" aria-hidden="true">
        <rect width="256" height="112" class="fill-emerald-50" />
        <path d="M0 78 C60 60 90 96 150 74 S230 60 256 70 V112 H0 Z" class="fill-sky-100" />
        <g class="stroke-white" stroke-width="7" fill="none" stroke-linecap="round">
            <path d="M-10 30 L90 22 L170 40 L270 18" />
            <path d="M60 -10 L78 60 L70 130" />
            <path d="M190 -10 L176 50 L200 130" />
        </g>
        <g class="stroke-white/70" stroke-width="3" fill="none" stroke-linecap="round">
            <path d="M0 52 L256 46" />
            <path d="M120 0 L128 112" />
        </g>
        <ellipse cx="128" cy="76" rx="9" ry="3.5" class="fill-black/20" />
        <path d="M128 74 C118 60 112 52 112 44 a16 16 0 1 1 32 0 c0 8 -6 16 -16 30 Z" class="fill-red-500" />
        <circle cx="128" cy="44" r="6" class="fill-white" />
    </svg>
    <span class="block px-3 py-2">
        <span class="block truncate text-sm font-medium text-ink">{{ $message->media_name ?? 'Localização' }}</span>
        <span class="mt-0.5 flex items-center gap-1 text-xs text-accent">
            Abrir no Google Maps
            <flux:icon.arrow-top-right-on-square class="size-3.5" />
        </span>
    </span>
</a>
