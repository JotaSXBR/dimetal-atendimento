// Modo do campo de resposta (Responder / Nota privada) e rascunhos (ver resources/views/livewire/inbox/inbox.blade.php).
//
// O rascunho de cada modo fica só neste navegador, por usuário e ticket (`localStorage`); trocar de modo guarda o texto do que
// sai e traz o do que entra. O campo é o mesmo (`wire:model="draft"`): o modo só decide se o envio chama `send` ou `saveNote`.
// O "digitando…" ao cliente só vale no modo Responder (ver o `x-on:input` do campo e o evento `composer-mode`).

document.addEventListener('alpine:init', () => {
    window.Alpine.data('composerMode', ($wire, ticketId, userId) => ({
        mode: 'reply',

        init() {
            this.$nextTick(() => this.set(this.load('reply'), false));
        },

        key(mode) {
            return `rascunho:${userId}:${ticketId}:${mode}`;
        },

        field() {
            return this.$root.querySelector('textarea');
        },

        load(mode) {
            return window.localStorage.getItem(this.key(mode)) ?? '';
        },

        save(mode, value) {
            if (value.trim() === '') {
                window.localStorage.removeItem(this.key(mode));
            } else {
                window.localStorage.setItem(this.key(mode), value);
            }
        },

        // Põe o texto no campo e avisa o `wire:model` (evento sintético: não é digitação, não anuncia presença).
        set(value, force = true) {
            const field = this.field();

            if (field === null || (! force && field.value.trim() !== '')) {
                return;
            }

            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        },

        remember(event) {
            if (event.isTrusted && event.target.tagName === 'TEXTAREA') {
                this.save(this.mode, event.target.value);
            }
        },

        toggle(to = null) {
            const next = to ?? (this.mode === 'note' ? 'reply' : 'note');

            if (next === this.mode) {
                return;
            }

            this.save(this.mode, this.field()?.value ?? '');
            this.mode = next;
            this.set(this.load(next));
            this.$dispatch('composer-mode', next);
            this.field()?.focus();
        },

        // "Adicionar texto às notas" (menu da bolha): leva o texto da mensagem ao campo, no modo nota, sem gravar.
        addToNote(text) {
            this.toggle('note');

            const current = this.field()?.value ?? '';

            this.set(current.trim() === '' ? text : `${current}\n${text}`);
            this.save('note', this.field()?.value ?? '');
        },

        submit() {
            const sent = this.mode;
            const call = sent === 'note' ? $wire.saveNote() : $wire.send();

            call.then(() => {
                if ($wire.draft === '') {
                    this.save(sent, '');
                }
            });
        },
    }));
});
