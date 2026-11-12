// Modo do campo de resposta (Responder / Nota privada) e rascunhos (ver resources/views/livewire/inbox/inbox.blade.php).
//
// O rascunho de cada modo fica só neste navegador, por usuário e ticket (`localStorage`); trocar de modo guarda o texto do que
// sai e traz o do que entra. O campo é o mesmo (`wire:model="draft"`): o modo só decide se o envio chama `send` ou `saveNote`.
// O "digitando…" ao cliente só vale no modo Responder (ver o `x-on:input` do campo e o evento `composer-mode`).

import { insertAt } from './composer-insert.js';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('composerMode', ($wire, ticketId, userId) => ({
        mode: 'reply',

        // O campo tem texto não vazio: com ele o mic vira Enviar (ver o form em inbox.blade.php).
        filled: false,

        init() {
            this.$nextTick(() => {
                this.set(this.load('reply'), false);
                this.sync();
            });
        },

        key(mode) {
            return `rascunho:${userId}:${ticketId}:${mode}`;
        },

        field() {
            return this.$root.querySelector('textarea');
        },

        sync() {
            this.filled = (this.field()?.value ?? '').trim() !== '';
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
            this.sync();
        },

        // Emoji e respostas rápidas entram no cursor, sem apagar o que já está escrito; o rascunho do modo atual é guardado.
        insert(text) {
            const field = this.field();

            if (field === null) {
                return;
            }

            const at = insertAt(field.value, field.selectionStart ?? field.value.length, field.selectionEnd ?? field.value.length, text);

            field.value = at.value;
            field.setSelectionRange(at.caret, at.caret);
            field.dispatchEvent(new Event('input', { bubbles: true }));
            this.save(this.mode, at.value);
            this.sync();
            field.focus();
        },

        // Digitar no formulário: guarda o rascunho e atualiza se há texto (o mic vira Enviar).
        onInput(event) {
            this.remember(event);
            this.sync();
        },

        focusField() {
            this.field()?.focus();
        },

        // Enter envia (Shift+Enter quebra a linha); não envia enquanto há arquivo subindo.
        enter(event, uploading) {
            if (event.shiftKey) {
                return;
            }

            event.preventDefault();

            if (uploading === 0) {
                this.submit();
            }
        },

        // Esc fecha a lista de respostas rápidas e devolve o foco ao campo.
        closeQuickReplies() {
            if ($wire.showQuickReplies) {
                $wire.set('showQuickReplies', false);
                this.$dispatch('composer-focus');
            }
        },

        insertQuickReply(body) {
            this.insert(body);
            $wire.set('showQuickReplies', false);
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

                this.$nextTick(() => this.sync());
            });
        },
    }));
});
