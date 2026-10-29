{{-- Player de áudio no estilo do WhatsApp Web: play, onda, tempo e velocidade (o <audio> nativo não tem velocidade). A
     lógica está em resources/js/audio-player.js; wire:ignore impede o Livewire de refazer o player a cada atualização. --}}
<div wire:ignore x-data="audioPlayer" data-audio-player data-src="{{ $url }}" class="flex w-64 max-w-full items-center gap-2 text-ink">
    <audio x-ref="audio" preload="none" class="hidden"></audio>

    <button
        type="button"
        x-on:click="toggle()"
        x-bind:aria-label="playing ? 'Pausar áudio' : 'Reproduzir áudio'"
        aria-label="Reproduzir áudio"
        class="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent text-white"
    >
        <flux:icon.play x-show="! playing" class="size-4" />
        <flux:icon.pause x-show="playing" style="display: none" class="size-4" />
    </button>

    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
        <div
            role="slider"
            tabindex="0"
            aria-label="Posição do áudio"
            aria-valuemin="0"
            aria-valuemax="100"
            x-bind:aria-valuenow="Math.round(progress * 100)"
            x-bind:aria-valuetext="timeLabel"
            x-on:pointerdown="seekStart($event)"
            x-on:pointermove="seekMove($event)"
            x-on:keydown="seekKey($event)"
            class="flex h-6 cursor-pointer touch-none items-center gap-px"
        >
            <template x-for="(bar, index) in bars" x-bind:key="index">
                <span
                    aria-hidden="true"
                    class="min-w-0.5 flex-1 rounded-full bg-current"
                    x-bind:class="index / bars.length < progress ? 'opacity-100' : 'opacity-30'"
                    x-bind:style="'height:' + Math.round(bar * 100) + '%'"
                ></span>
            </template>
        </div>
        <span class="font-mono text-[10px] text-ink-3 tabular-nums" x-text="timeLabel">0:00</span>
    </div>

    <button
        type="button"
        x-on:click="cycleSpeed()"
        aria-label="Velocidade de reprodução"
        class="shrink-0 rounded bg-ink/10 px-1.5 py-0.5 text-xs font-medium tabular-nums"
        x-text="speed + '×'"
    >1×</button>
</div>
