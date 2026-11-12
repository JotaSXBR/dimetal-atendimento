// Sino e banner de recuperação dos alertas (resources/views/livewire/alerts). A lógica mora aqui, e não em expressões de atributo,
// porque o build CSP do Alpine (livewire.csp.js) não avalia strings: ver tests/Unit/AlpineCspSubsetTest.php.

const BELL_GAP = 8;
const RECOVERED_MS = 10000;

document.addEventListener('alpine:init', () => {
    // O menu lateral rola (overflow) e cortaria o painel: ele é `fixed`, posicionado abaixo do botão que o abriu.
    window.Alpine.data('alertBell', () => ({
        open: false,
        x: 0,
        y: 0,

        get menuStyle() {
            return `left: ${this.x}px; top: ${this.y}px`;
        },

        toggle(event) {
            const rect = event.currentTarget.getBoundingClientRect();

            this.x = rect.left;
            this.y = rect.bottom + BELL_GAP;
            this.open = !this.open;
        },
    }));

    // "WhatsApp voltou" some sozinho em 10 s.
    window.Alpine.data('recoveryBanner', () => ({
        timer: null,

        init() {
            this.timer = setTimeout(() => this.$wire.dismissRecovered(), RECOVERED_MS);
        },

        destroy() {
            clearTimeout(this.timer);
        },
    }));
});
