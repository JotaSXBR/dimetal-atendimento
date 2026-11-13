{{-- Player de áudio no estilo dos apps de chat: play cheio, onda, tempo e velocidade (o <audio> nativo não tem velocidade). A lógica
     está em resources/js/audio-player.js; wire:ignore impede o Livewire de refazer o player a cada atualização.
     Grade de duas colunas: o play e a onda dividem a linha 1 (por isso ficam alinhados); o tempo e a velocidade ficam na linha 2,
     sob a onda. --}}
<div wire:ignore x-data="audioPlayer" data-audio-player data-src="{{ $url }}" class="grid w-72 max-w-full grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-1 text-ink">
    <audio x-ref="audio" preload="none" class="hidden"></audio>

    <button
        type="button"
        x-on:click="toggle()"
        x-bind:aria-label="playing ? 'Pausar áudio' : 'Reproduzir áudio'"
        aria-label="Reproduzir áudio"
        class="row-start-1 flex size-10 shrink-0 items-center justify-center rounded-full bg-accent text-accent-foreground shadow-sm transition-transform hover:scale-105 active:scale-95"
    >
        {{-- O triângulo do play parece deslocado para a esquerda no centro do círculo: `ms-0.5` o compensa. --}}
        <flux:icon.play variant="solid" x-show="! playing" class="ms-0.5 size-5" />
        <flux:icon.pause variant="solid" x-show="playing" style="display: none" class="size-5" />
    </button>

    <div
        role="slider"
        tabindex="0"
        aria-label="Posição do áudio"
        aria-valuemin="0"
        aria-valuemax="100"
        x-bind:aria-valuenow="progressPercent"
        x-bind:aria-valuetext="timeLabel"
        x-on:pointerdown="seekStart($event)"
        x-on:pointermove="seekMove($event)"
        x-on:keydown="seekKey($event)"
        class="row-start-1 flex h-8 cursor-pointer touch-none items-center gap-0.5"
    >
        <template x-for="(bar, index) in bars" x-bind:key="index">
            <span
                aria-hidden="true"
                class="min-w-0.5 flex-1 rounded-full bg-current"
                x-bind:class="index / bars.length < progress ? 'opacity-100' : 'opacity-35'"
                x-bind:style="barStyle(bar)"
            ></span>
        </template>
    </div>

    <div data-testid="audio-meta" class="col-start-2 flex items-center justify-between gap-2">
        <span class="font-mono text-[11px] text-ink-2 tabular-nums" x-text="timeLabel">0:00</span>

        <button
            type="button"
            x-on:click="cycleSpeed()"
            aria-label="Velocidade de reprodução"
            class="inline-flex min-h-6 shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-semibold text-ink-2 tabular-nums ring-1 ring-ink/20 transition-colors hover:bg-ink/5 hover:text-ink"
            x-text="speed + '×'"
        >1×</button>
    </div>
</div>
