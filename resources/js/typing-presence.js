// "Digitando…" do atendente no campo de resposta (ver resources/views/livewire/inbox/inbox.blade.php).
//
// Padrão dos chats: o início é limitado (um aviso a cada THROTTLE_MS, nunca por tecla) e o fim é adiado (IDLE_MS sem digitar
// manda a pausa; campo vazio ou sair do campo também). O servidor só protege com um teto de chamadas por minuto.

const THROTTLE_MS = 5000;
const IDLE_MS = 3000;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('typingPresence', ($wire, ticketId) => ({
        lastPing: 0,
        idle: null,
        typing: false,

        input(value) {
            if (value.trim() === '') {
                this.stop();

                return;
            }

            const now = Date.now();

            if (now - this.lastPing >= THROTTLE_MS) {
                this.lastPing = now;
                this.typing = true;
                $wire.typing(true, ticketId);
            }

            clearTimeout(this.idle);
            this.idle = setTimeout(() => this.stop(), IDLE_MS);
        },

        stop() {
            clearTimeout(this.idle);

            if (this.typing) {
                this.typing = false;
                this.lastPing = 0;
                $wire.typing(false, ticketId);
            }
        },

        destroy() {
            this.stop();
        },
    }));
});
