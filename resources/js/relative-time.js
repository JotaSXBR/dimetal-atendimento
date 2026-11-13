// "há 3 minutos" que continua certo: o servidor desenha o texto uma vez e ele congelaria; aqui o navegador recalcula a partir
// do instante gravado (ISO 8601), a cada 30 s. Usado no sino de alertas (resources/views/livewire/alerts/bell.blade.php) e no card da
// Fila (estilo `narrow`: "há 3 min").

const UNITS = [
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
    ['second', 1],
];

const formatters = {
    long: new Intl.RelativeTimeFormat('pt-BR', { numeric: 'always' }),
    narrow: new Intl.RelativeTimeFormat('pt-BR', { numeric: 'always', style: 'narrow' }),
};

/** O texto relativo de `iso` agora (ou em `now`, para teste). Tem o mesmo formato de App\Support\WaitLabel::narrow no servidor. */
export function label(iso, style, now = Date.now()) {
    const seconds = Math.max(0, Math.round((now - new Date(iso).getTime()) / 1000));

    for (const [unit, size] of UNITS) {
        if (seconds >= size || unit === 'second') {
            return formatters[style].format(-Math.floor(seconds / size), unit);
        }
    }

    return '';
}

// Sem `document` (testes em node) só a função `label` interessa.
if (typeof document !== 'undefined') document.addEventListener('alpine:init', () => {
    window.Alpine.data('relativeTime', (iso, style = 'long') => ({
        label: label(iso, style),
        timer: null,

        init() {
            this.timer = setInterval(() => (this.label = label(iso, style)), 30000);
        },

        destroy() {
            clearInterval(this.timer);
        },
    }));
});
