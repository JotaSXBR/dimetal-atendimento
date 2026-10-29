// Player de áudio do inbox (ver resources/views/livewire/inbox/audio-player.blade.php).
//
// O arquivo é buscado uma vez só: a mesma resposta serve para desenhar a onda (decodeAudioData) e, como blob, para
// tocar. Arquivo grande demais ou falha ao decodificar cai para a URL direta, com barras fixas.

const SPEEDS = [1, 1.5, 2];
const BARS = 40;
const MAX_DECODE_BYTES = 3_000_000;
const FALLBACK_BARS = Array.from({ length: BARS }, (_, i) => 0.3 + 0.2 * Math.abs(Math.sin(i * 1.7)));

function formatTime(seconds) {
    if (!Number.isFinite(seconds) || seconds < 0) {
        return '0:00';
    }

    const whole = Math.floor(seconds);

    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}

async function decode(buffer) {
    const Context = window.AudioContext || window.webkitAudioContext;
    const context = new Context();

    try {
        const audio = await context.decodeAudioData(buffer.slice(0));
        const data = audio.getChannelData(0);
        const step = Math.max(1, Math.floor(data.length / BARS));
        const peaks = Array.from({ length: BARS }, (_, i) => {
            let max = 0;
            for (let j = i * step; j < Math.min(data.length, (i + 1) * step); j++) {
                max = Math.max(max, Math.abs(data[j]));
            }

            return max;
        });
        const top = Math.max(...peaks, 0.001);

        return { bars: peaks.map((p) => Math.max(0.12, p / top)), duration: audio.duration };
    } finally {
        context.close();
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('audioPlayer', () => ({
        src: '',
        playing: false,
        current: 0,
        duration: 0,
        speed: 1,
        bars: FALLBACK_BARS,
        prepared: false,

        init() {
            const audio = this.$refs.audio;
            this.src = this.$el.dataset.src;

            audio.addEventListener('timeupdate', () => (this.current = audio.currentTime));
            audio.addEventListener('loadedmetadata', () => {
                if (Number.isFinite(audio.duration)) {
                    this.duration = audio.duration;
                }
            });
            audio.addEventListener('play', () => {
                audio.playbackRate = this.speed;
                this.playing = true;
                window.dispatchEvent(new CustomEvent('audio-player:play', { detail: this.$el }));
            });
            audio.addEventListener('pause', () => (this.playing = false));
            audio.addEventListener('ended', () => {
                this.playing = false;
                this.current = 0;
            });

            // Só um áudio toca por vez, como no WhatsApp Web.
            window.addEventListener('audio-player:play', (event) => {
                if (event.detail !== this.$el) {
                    audio.pause();
                }
            });

            // Só busca o arquivo quando o player aparece na tela.
            const observer = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    observer.disconnect();
                    this.prepare();
                }
            });
            observer.observe(this.$el);
        },

        async prepare() {
            if (this.prepared) {
                return;
            }

            this.prepared = true;
            const audio = this.$refs.audio;

            try {
                const response = await fetch(this.src, { credentials: 'same-origin' });
                const size = Number(response.headers.get('content-length') ?? 0);

                if (!response.ok || size > MAX_DECODE_BYTES) {
                    audio.src = this.src;

                    return;
                }

                const buffer = await response.arrayBuffer();
                audio.src = URL.createObjectURL(new Blob([buffer], { type: response.headers.get('content-type') ?? 'audio/ogg' }));

                const decoded = await decode(buffer);
                this.bars = decoded.bars;
                this.duration = this.duration || decoded.duration;
            } catch {
                audio.src = audio.src || this.src;
            }
        },

        get progress() {
            return this.duration > 0 ? Math.min(1, this.current / this.duration) : 0;
        },

        get timeLabel() {
            return this.playing || this.current > 0 ? formatTime(this.current) : formatTime(this.duration);
        },

        async toggle() {
            const audio = this.$refs.audio;

            if (!audio.src) {
                await this.prepare();
            }

            if (audio.paused) {
                audio.play().catch(() => (this.playing = false));
            } else {
                audio.pause();
            }
        },

        cycleSpeed() {
            this.speed = SPEEDS[(SPEEDS.indexOf(this.speed) + 1) % SPEEDS.length];
            this.$refs.audio.playbackRate = this.speed;
        },

        seekTo(fraction) {
            if (this.duration > 0) {
                this.$refs.audio.currentTime = Math.min(1, Math.max(0, fraction)) * this.duration;
            }
        },

        seekFromPointer(event) {
            const rect = event.currentTarget.getBoundingClientRect();
            this.seekTo((event.clientX - rect.left) / rect.width);
        },

        seekStart(event) {
            event.currentTarget.setPointerCapture(event.pointerId);
            this.seekFromPointer(event);
        },

        seekMove(event) {
            if (event.currentTarget.hasPointerCapture(event.pointerId)) {
                this.seekFromPointer(event);
            }
        },

        seekKey(event) {
            const step = { ArrowLeft: -5, ArrowRight: 5 }[event.key];

            if (step !== undefined) {
                event.preventDefault();
                this.$refs.audio.currentTime = Math.max(0, this.$refs.audio.currentTime + step);
            }
        },
    }));
});
