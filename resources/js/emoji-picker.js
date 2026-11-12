// Seletor de emoji do compositor (ver o botão "Emoji" em resources/views/livewire/inbox/inbox.blade.php).
//
// O web component `emoji-picker-element` só é baixado na primeira abertura. Os dados (português) saem do próprio app, de
// public/vendor/emoji/pt.json: nada é pedido a CDN nem a terceiros. O emoji escolhido sobe como o evento `emoji-insert`, que o
// `composerMode` trata inserindo no cursor do campo; fechar com Esc devolve o foco ao campo (`composer-focus`).

import { shown } from './shown.js';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('emojiPicker', () => ({
        open: false,
        picker: null,

        async toggle() {
            if (this.open) {
                this.close();

                return;
            }

            await this.show();
        },

        async show() {
            if (this.picker === null) {
                const [{ Picker }, { default: i18n }] = await Promise.all([import('emoji-picker-element'), import('emoji-picker-element/i18n/pt_BR')]);

                this.picker = new Picker({ dataSource: '/vendor/emoji/pt.json', locale: 'pt', i18n });
                this.picker.addEventListener('emoji-click', (event) => this.pick(event.detail.unicode ?? ''));
                this.$refs.panel.appendChild(this.picker);
            }

            // O tema pode ter mudado desde a última abertura.
            this.picker.classList.remove('dark', 'light');
            this.picker.classList.add(document.documentElement.classList.contains('dark') ? 'dark' : 'light');

            this.open = true;
            await this.$nextTick();
            await shown(this.$refs.panel);
            this.picker.shadowRoot?.querySelector('input[type="search"]')?.focus();
        },

        pick(unicode) {
            if (unicode === '') {
                return;
            }

            this.open = false;
            this.$dispatch('emoji-insert', unicode);
        },

        // Esc devolve o foco ao campo; o clique fora só fecha (o foco fica onde a pessoa clicou).
        close(refocus = false) {
            if (! this.open) {
                return;
            }

            this.open = false;

            if (refocus) {
                this.$dispatch('composer-focus');
            }
        },
    }));
});
