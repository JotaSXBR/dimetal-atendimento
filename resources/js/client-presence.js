// "Digitando…" e "gravando áudio…" do cliente (ver resources/views/livewire/inbox/inbox.blade.php).
//
// O servidor transmite `client.presence` (`{ticket_id, state}`) pelo canal `inbox`. O estado vive só aqui, num store do Alpine,
// e expira sozinho: cada aviso de digitando/gravando vale `ttl` segundos (rede de segurança se o `paused` nunca chegar), `parou`
// apaga na hora, e a mensagem do cliente (`message.created` de entrada) também.

const LABELS = { digitando: 'digitando…', gravando: 'gravando áudio…' };

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    Alpine.store('clientPresence', {
        states: {},
        timers: {},
        ttlMs: 15000,

        // Estado guardado no servidor (Redis) no momento em que a tela foi montada; quem abre no meio da digitação o vê.
        seed(states, ttlSeconds) {
            this.ttlMs = ttlSeconds * 1000;

            Object.entries(states ?? {}).forEach(([id, state]) => this.set(Number(id), state));
        },

        set(ticketId, state) {
            clearTimeout(this.timers[ticketId]);

            if (!LABELS[state]) {
                const { [ticketId]: _gone, ...rest } = this.states;
                this.states = rest;

                return;
            }

            this.states = { ...this.states, [ticketId]: state };
            this.timers[ticketId] = setTimeout(() => this.set(ticketId, 'parou'), this.ttlMs);
        },

        label(ticketId) {
            return LABELS[this.states[ticketId]] ?? '';
        },
    });

    window.Echo?.private('inbox')
        .listen('.client.presence', (e) => Alpine.store('clientPresence').set(e.ticket_id, e.state))
        .listen('.message.created', (e) => e.direction === 'in' && Alpine.store('clientPresence').set(e.ticket_id, 'parou'));
});
