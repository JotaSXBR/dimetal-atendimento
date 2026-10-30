// Som e balão dos alertas, um módulo só (o "olhar" fica nas faixas do servidor; aqui só se decide tocar ou não).
// As regras puras estão em alerts-rules.js. Dois tipos de gatilho:
//   - estado: a faixa visível na página (`[data-alert=…]`) liga e desliga o som repetido, então quem abre a tela no meio
//     de uma queda também ouve, e a repetição para sozinha quando a faixa some;
//   - evento: mensagem, fila, atribuição e SLA chegam pelo canal privado do usuário e tocam uma vez.
import { MAX_REPEATS, repeatMs, soundFor, wantsBalloon } from './alerts-rules';

const DEFAULTS = { sound: {}, volume: 70, repeat: true, desktop_notifications: false, mute_critical_until: null };

// Três tons fixos sintetizados (sem arquivos): o navegador só libera áudio depois de um clique.
const TONES = {
    comum: [[880, 0.16]],
    sla: [[660, 0.16], [880, 0.16]],
    critico: [[988, 0.2], [740, 0.2], [988, 0.2]],
};

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;

function parse(raw) {
    try {
        return { ...DEFAULTS, ...JSON.parse(raw ?? '{}') };
    } catch {
        return { ...DEFAULTS };
    }
}

function start(id) {
    let settings = parse(meta('alert-settings'));
    let audio = null;
    let leader = false;
    let offline = false;
    const loops = new Map();

    const openTicket = () => {
        const value = document.querySelector('[data-open-ticket]')?.dataset.openTicket;

        return value ? Number(value) : null;
    };

    const context = () => (audio ??= new (window.AudioContext || window.webkitAudioContext)());
    const anySound = () => Object.values(settings.sound ?? {}).some(Boolean);

    function play(kind, ignoreLeader = false) {
        const ctx = context();

        if ((!leader && !ignoreLeader) || ctx.state !== 'running') {
            return;
        }

        let at = ctx.currentTime;

        for (const [freq, length] of TONES[kind] ?? []) {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = kind === 'critico' ? 'square' : 'sine';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.0001, at);
            gain.gain.exponentialRampToValueAtTime(Math.max(0.0001, (settings.volume / 100) * 0.4), at + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, at + length);
            osc.connect(gain).connect(ctx.destination);
            osc.start(at);
            osc.stop(at + length + 0.02);
            at += length + 0.06;
        }
    }

    async function unlock() {
        const ctx = context();

        if (ctx.state !== 'running') {
            await ctx.resume().catch(() => {});
        }

        render();
    }

    function balloon(kind) {
        if (!leader || !wantsBalloon(kind, settings, window.Notification?.permission)) {
            return;
        }

        const [title, body] = kind === 'connection_down'
            ? ['WhatsApp desconectado', 'Reconecte em Configurações > Canais.']
            : ['SLA estourado', 'Há tickets esperando além do prazo.'];

        // Sem texto de cliente, de propósito.
        new Notification(title, { body, tag: `dimetal-${kind}`, silent: true });
    }

    function once(kind, ctx = {}) {
        const sound = soundFor(kind, settings, { ...ctx, openTicketId: openTicket(), focused: document.hasFocus(), now: Date.now() });

        if (sound) {
            play(sound);
        }
    }

    function loop(key, kind) {
        if (loops.has(key)) {
            return;
        }

        once(kind);
        balloon(kind);

        if (repeatMs(kind, settings) === null) {
            loops.set(key, { timer: null });

            return;
        }

        // Relê a regra a cada volta: silenciar ou desligar a repetição vale na hora.
        let count = 1;
        const tick = () => {
            const every = repeatMs(kind, settings);

            if (++count > MAX_REPEATS || every === null) {
                loops.set(key, { timer: null });

                return;
            }

            once(kind);
            loops.set(key, { timer: setTimeout(tick, every) });
        };
        loops.set(key, { timer: setTimeout(tick, repeatMs(kind, settings)) });
    }

    function stop(key) {
        clearTimeout(loops.get(key)?.timer);
        loops.delete(key);
    }

    const STATES = [
        { key: 'connection', kind: 'connection_down', active: () => offline || document.querySelector('[data-alert="connection-down"]') !== null },
        { key: 'sla', kind: 'sla_breached', active: () => document.querySelector('[data-alert="sla-breached"]') !== null },
    ];

    function render() {
        const off = document.getElementById('alerts-offline');
        const strip = document.getElementById('alerts-audio');

        if (off) {
            off.hidden = !offline;
        }

        if (strip) {
            strip.hidden = !anySound() || (audio !== null && audio.state === 'running');
        }
    }

    function sync() {
        for (const state of STATES) {
            state.active() ? loop(state.key, state.kind) : stop(state.key);
        }

        render();
    }

    // Uma aba só toca: a primeira que pega o lock; sem Web Locks, um batimento no localStorage.
    if (navigator.locks) {
        navigator.locks.request('dimetal-alerts-leader', () => {
            leader = true;
            sync();

            return new Promise(() => {});
        });
    } else {
        const tab = Math.random().toString(36).slice(2);
        const claim = () => {
            const held = JSON.parse(localStorage.getItem('dimetal-alerts-leader') ?? 'null');

            leader = !held || held.tab === tab || Date.now() - held.at > 3000;

            if (leader) {
                localStorage.setItem('dimetal-alerts-leader', JSON.stringify({ tab, at: Date.now() }));
            }
        };
        claim();
        setInterval(claim, 1000);
    }

    // O `wire:navigate` troca o <body> inteiro: observar o <html>, que sobrevive à troca, evita o observer ficar em um nó morto.
    let frame = null;
    new MutationObserver(() => {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(sync);
    }).observe(document.documentElement, { childList: true, subtree: true });
    document.addEventListener('livewire:navigated', sync);

    // Qualquer clique ou tecla libera o áudio (a política do navegador pede um gesto).
    document.addEventListener('click', (event) => {
        if (event.target.closest('#alerts-audio-enable')) {
            unlock().then(() => play('comum', true));
        } else if (audio === null || audio.state !== 'running') {
            unlock();
        }
    }, { capture: true });
    document.addEventListener('keydown', () => (audio?.state === 'running' ? null : unlock()), { capture: true });

    window.addEventListener('alerts-preview', async (event) => {
        await unlock();
        play(Object.hasOwn(TONES, event.detail?.kind) ? event.detail.kind : 'comum', true);
    });

    const listenSettings = () => Livewire.on('alert-settings-updated', (payload) => {
        const next = payload?.settings ?? payload?.[0]?.settings ?? payload?.[0];

        if (next) {
            settings = parse(JSON.stringify(next));
            sync();
        }
    });
    window.Livewire ? listenSettings() : document.addEventListener('livewire:init', listenSettings);

    window.Echo?.private(`users.${id}`)
        .listen('.message.created', (e) => e.direction === 'in' && once('message', { ticketId: e.ticket_id }))
        .listen('.alert.queue', (e) => once('queue', { ticketId: e.ticket_id }))
        .notification((n) => {
            if (['assigned', 'sla_warn'].includes(n.kind)) {
                once(n.kind, { ticketId: n.ticket_id });
            } else if (n.kind === 'sla_breached') {
                once('sla_breached');
            }
        });

    // Falha de conexão do navegador com o nosso servidor: segundo alerta crítico. O Reverb não reenvia o que se perdeu,
    // então ao voltar a tela recarrega o estado.
    window.Echo?.connector?.pusher?.connection?.bind('state_change', ({ current }) => {
        const down = ['unavailable', 'disconnected', 'failed'].includes(current);

        if (down !== offline) {
            offline = down;
            sync();

            if (!down && window.Livewire) {
                Livewire.dispatch('alerts-refresh');
            }
        }
    });

    sync();
}

const userId = meta('alert-user');

if (userId) {
    start(Number(userId));
}
