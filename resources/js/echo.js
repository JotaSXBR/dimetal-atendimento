import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;
const url = new URL(meta('reverb-url') || window.location.origin);
const tls = url.protocol === 'https:';

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: meta('reverb-key'),
    wsHost: url.hostname,
    wsPort: url.port || 80,
    wssPort: url.port || 443,
    forceTLS: tls,
    enabledTransports: ['ws', 'wss'],
});
