// Gravador de mensagem de voz do inbox (ver resources/views/livewire/inbox/inbox.blade.php).
//
// Fluxo: gravar -> ouvir -> regravar, descartar ou enviar. Enquanto grava, avisa o servidor a cada poucos segundos para o
// cliente ver "gravando áudio…"; ao parar ou descartar, avisa que parou. O arquivo sobe como `voice` e o servidor o envia
// como mensagem de voz (o WAHA converte para OGG/OPUS).

const MAX_SECONDS = 300;
const MIN_SECONDS = 1;
const HEARTBEAT_MS = 4000;
const MIME_CANDIDATES = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];

function extensionFor(mime) {
    if (mime.includes('mp4')) {
        return 'm4a';
    }

    return mime.includes('ogg') ? 'ogg' : 'webm';
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('voiceRecorder', ($wire, ticketId) => ({
        phase: 'idle',
        seconds: 0,
        url: null,
        error: '',
        sending: false,
        recorder: null,
        stream: null,
        chunks: [],
        blob: null,
        mime: '',
        timer: null,
        beat: null,

        get clock() {
            const minutes = Math.floor(this.seconds / 60);

            return `${minutes}:${String(this.seconds % 60).padStart(2, '0')}`;
        },

        setPhase(phase) {
            this.phase = phase;
            window.dispatchEvent(new CustomEvent('voice-phase', { detail: phase }));
        },

        async start() {
            this.error = '';

            if (this.phase !== 'idle') {
                return;
            }

            if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
                this.error = 'Este navegador não grava áudio.';

                return;
            }

            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch {
                this.error = 'Permita o microfone no navegador para gravar.';

                return;
            }

            const supported = MIME_CANDIDATES.find((type) => MediaRecorder.isTypeSupported(type));
            this.recorder = new MediaRecorder(this.stream, supported ? { mimeType: supported } : undefined);
            this.mime = this.recorder.mimeType || supported || 'audio/webm';
            this.chunks = [];
            this.recorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    this.chunks.push(event.data);
                }
            };
            this.recorder.onstop = () => this.finish();
            this.recorder.start(250);

            this.seconds = 0;
            this.setPhase('recording');
            this.timer = setInterval(() => {
                this.seconds += 1;

                if (this.seconds >= MAX_SECONDS) {
                    this.error = 'Limite de 5 minutos: a gravação parou.';
                    this.stop();
                }
            }, 1000);
            $wire.recording(true, ticketId);
            this.beat = setInterval(() => $wire.recording(true, ticketId), HEARTBEAT_MS);
        },

        stop() {
            this.clearTimers();

            if (this.recorder?.state === 'recording') {
                this.recorder.stop();
            }
        },

        finish() {
            this.releaseStream();

            if (this.seconds < MIN_SECONDS) {
                this.discard();

                return;
            }

            this.blob = new Blob(this.chunks, { type: this.mime });
            this.url = URL.createObjectURL(this.blob);
            this.setPhase('preview');
            $wire.recording(false, ticketId);
        },

        discard() {
            const wasRecording = this.phase === 'recording';

            this.clearTimers();

            if (this.recorder?.state === 'recording') {
                this.recorder.onstop = null;
                this.recorder.stop();
            }

            this.releaseStream();

            if (this.url) {
                URL.revokeObjectURL(this.url);
            }

            this.url = null;
            this.blob = null;
            this.chunks = [];
            this.sending = false;
            this.setPhase('idle');

            if (wasRecording) {
                $wire.recording(false, ticketId);
            }
        },

        rerecord() {
            this.discard();
            this.start();
        },

        send() {
            if (!this.blob || this.sending) {
                return;
            }

            this.sending = true;
            this.error = '';

            const file = new File([this.blob], `audio.${extensionFor(this.mime)}`, { type: this.mime });

            $wire.$upload(
                'voice',
                file,
                async () => {
                    await $wire.sendVoice(ticketId);
                    this.discard();
                },
                () => {
                    this.sending = false;
                    this.error = 'Não foi possível enviar o áudio. Tente de novo.';
                },
                () => {},
            );
        },

        clearTimers() {
            clearInterval(this.timer);
            clearInterval(this.beat);
            this.timer = null;
            this.beat = null;
        },

        releaseStream() {
            this.stream?.getTracks().forEach((track) => track.stop());
            this.stream = null;
        },

        // O elemento saiu da tela (outro ticket, navegação): libera o microfone e avisa que parou de gravar.
        destroy() {
            this.discard();
        },
    }));
});
