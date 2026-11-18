// Pequenos componentes das telas de Configuração (setores, assinatura, alertas). A lógica fica aqui, e não em `x-data` inline,
// porque o build CSP do Alpine não aceita método, `if` nem várias instruções no atributo (ver tests/Unit/AlpineCspSubsetTest.php).

import { centerScrollLeft } from './scroll-center.js';
import { shown } from './shown.js';

document.addEventListener('alpine:init', () => {
    // Menu de Configuração abaixo de lg rola na horizontal: o item atual nasce no meio da vista (e a barra de rolagem
    // acompanha). Roda a cada carga, inclusive após wire:navigate, que monta o menu de novo. No lg nada rola e nada muda.
    window.Alpine.data('currentInView', () => ({
        init() {
            const item = this.$el.querySelector('[aria-current="page"]');

            if (item) {
                this.$el.scrollLeft = centerScrollLeft({
                    container: this.$el.getBoundingClientRect(),
                    item: item.getBoundingClientRect(),
                    scrollLeft: this.$el.scrollLeft,
                });
            }
        },
    }));

    // Renomear setor na própria linha (resources/views/livewire/settings/sectors.blade.php).
    window.Alpine.data('sectorRow', (name) => ({
        editing: false,
        name,

        async start() {
            this.editing = true;
            await this.$nextTick();
            await shown(this.$refs.field);
            this.$refs.field.focus();
        },

        // Volta ao nome que o servidor tem agora (a view reenvia o nome atual a cada renderização).
        cancel(current) {
            this.editing = false;
            this.name = current;
        },

        save(id) {
            return this.$wire.rename(id, this.name).then(() => {
                this.editing = false;
            });
        },
    }));

    // Barra de formatação da assinatura (resources/views/livewire/settings/company.blade.php): envolve a seleção do campo com a marca.
    window.Alpine.data('signatureToolbar', () => ({
        wrap(mark) {
            const el = this.$refs.template.querySelector('textarea');
            const start = el.selectionStart;
            const end = el.selectionEnd;

            el.setRangeText(mark + el.value.slice(start, end) + mark, start, end, 'select');
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.focus();
        },
    }));

    // Chave "Balão do navegador" (resources/views/pages/settings/⚡alerts.blade.php): ligar pede a permissão; sem ela, volta a desligar.
    window.Alpine.data('desktopAlerts', () => ({
        async ask() {
            await this.$nextTick();

            if (!this.$wire.desktop) {
                return;
            }

            if (!('Notification' in window)) {
                this.$wire.desktop = false;

                return;
            }

            if (Notification.permission !== 'granted' && (await Notification.requestPermission()) !== 'granted') {
                this.$wire.desktop = false;
            }
        },
    }));
});
