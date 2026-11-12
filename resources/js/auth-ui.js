// Componentes de login e segurança da conta: passkeys, desafio de 2FA e o modal de ativação do 2FA. A lógica fica aqui, e não em
// `x-data` inline, porque o build CSP do Alpine não aceita método, seta nem `?.` no atributo (tests/Unit/AlpineCspSubsetTest.php).
// `window.Passkeys` vem de resources/js/passkeys.js (carregado à parte pelas telas que o usam).

import { shown } from './shown.js';

const BROWSERS = [
    { pattern: /Edg|Edge/, name: 'Edge' },
    { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
    { pattern: /Firefox|FxiOS/, name: 'Firefox' },
    { pattern: /Chrome|CriOS/, name: 'Chrome' },
    { pattern: /Safari/, name: 'Safari' },
];

const SYSTEMS = [
    { pattern: /iPhone/, name: 'iPhone' },
    { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
    { pattern: /Android/, name: 'Android' },
    { pattern: /Mac/, name: 'Mac' },
    { pattern: /Windows/, name: 'Windows' },
];

function defaultPasskeyName() {
    const ua = navigator.userAgent;
    const browser = BROWSERS.find(({ pattern }) => pattern.test(ua))?.name;
    const os = SYSTEMS.find(({ pattern }) => pattern.test(ua))?.name;

    return [browser, os].filter(Boolean).join(' on ') || '';
}

// Cancelar o diálogo do sistema não é erro para mostrar.
function isCancel(error) {
    return error.constructor?.name === 'UserCancelledError';
}

document.addEventListener('alpine:init', () => {
    // Cadastrar um passkey (resources/views/components/passkey-registration.blade.php).
    window.Alpine.data('passkeyRegistration', () => ({
        supported: false,
        showForm: false,
        name: '',
        loading: false,
        error: null,

        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },

        init() {
            this.name = defaultPasskeyName();
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },

        // Ao abrir o formulário (o campo nasce dentro de um `x-if`): foca o nome.
        async focusName() {
            await this.$nextTick();

            const field = this.$refs.passkeyNameInput;

            if (field) {
                await shown(field);
                field.focus();
            }
        },

        async register() {
            if (!this.name.trim()) {
                return;
            }

            this.loading = true;
            this.error = null;

            try {
                await window.Passkeys.register({ name: this.name });
                this.name = '';
                this.showForm = false;
                await this.$wire.loadPasskeys();
            } catch (e) {
                if (!isCancel(e)) {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },

        cancel() {
            this.showForm = false;
            this.name = '';
            this.error = null;
        },
    }));

    // Entrar com passkey (resources/views/components/passkey-verify.blade.php); as rotas vêm da view.
    window.Alpine.data('passkeyVerify', (optionsUrl, submitUrl) => ({
        supported: false,
        loading: false,
        error: null,

        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },

        init() {
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },

        async verify() {
            this.loading = true;
            this.error = null;

            try {
                const response = await window.Passkeys.verify({ routes: { options: optionsUrl, submit: submitUrl } });

                window.Livewire.navigate(response.redirect || '/inbox');
            } catch (e) {
                if (!isCancel(e)) {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },
    }));

    // Desafio de 2FA no login (resources/views/pages/auth/two-factor-challenge.blade.php): código do app ou código de recuperação.
    window.Alpine.data('twoFactorChallenge', (showRecoveryInput) => ({
        showRecoveryInput,
        code: '',
        recovery_code: '',

        async focus(field) {
            await this.$nextTick();
            await shown(field);

            const target = field.matches('input') ? field : field.querySelector('input');

            target?.focus();
        },

        focusOtp() {
            return this.focus(this.$refs.otp);
        },

        init() {
            if (!this.showRecoveryInput) {
                this.focusOtp();
            }
        },

        toggleInput() {
            this.showRecoveryInput = !this.showRecoveryInput;
            this.code = '';
            this.recovery_code = '';

            return this.showRecoveryInput ? this.focus(this.$refs.recovery_code) : this.focusOtp();
        },
    }));

    // Foca o primeiro campo do bloco assim que ele aparece (modal de ativação do 2FA).
    window.Alpine.data('focusFirstInput', () => ({
        async init() {
            await this.$nextTick();
            await shown(this.$el);
            this.$el.querySelector('input')?.focus();
        },
    }));

    // Chave manual do 2FA: copia para a área de transferência e mostra o "copiado" por 1,5 s.
    window.Alpine.data('copyKey', () => ({
        copied: false,

        async copy(key) {
            try {
                await navigator.clipboard.writeText(key);
                this.copied = true;
                setTimeout(() => (this.copied = false), 1500);
            } catch (e) {
                console.warn('Could not copy to clipboard');
            }
        },
    }));
});
