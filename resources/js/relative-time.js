// "há 3 minutos" que continua certo: o servidor desenha o texto uma vez e ele congelaria; aqui o navegador recalcula a partir
// do instante gravado (ISO 8601), a cada 30 s. Usado no sino de alertas (resources/views/livewire/alerts/bell.blade.php).

const UNITS = [
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
    ['second', 1],
];

const formatter = new Intl.RelativeTimeFormat('pt-BR', { numeric: 'always' });

function label(iso) {
    const seconds = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));

    for (const [unit, size] of UNITS) {
        if (seconds >= size || unit === 'second') {
            return formatter.format(-Math.floor(seconds / size), unit);
        }
    }

    return '';
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('relativeTime', (iso) => ({
        label: label(iso),
        timer: null,

        init() {
            this.timer = setInterval(() => (this.label = label(iso)), 30000);
        },

        destroy() {
            clearInterval(this.timer);
        },
    }));
});
