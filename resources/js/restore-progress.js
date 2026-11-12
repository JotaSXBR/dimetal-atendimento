// Página de andamento da restauração do banco (`/restauracao`). JS puro, sem Alpine nem Livewire: a página abre com o sistema em
// manutenção e sem banco, então só consulta o JSON de estado (Redis) a cada 2 s e desenha os passos.
const root = document.querySelector('[data-restore-progress]');

if (root) {
    const stateUrl = document.querySelector('meta[name="restore-state-url"]')?.content;
    const doneUrl = document.querySelector('meta[name="restore-done-url"]')?.content;
    const list = root.querySelector('[data-steps]');
    const title = root.querySelector('[data-title]');
    const message = root.querySelector('[data-message]');
    const back = root.querySelector('[data-back]');

    const markers = { done: 'feito', failed: 'falhou', running: 'em andamento…', pending: 'aguardando' };
    const tones = { done: 'text-ok', failed: 'text-danger', running: 'text-accent-content', pending: 'text-ink-3' };

    const render = (state) => {
        title.textContent = state.status === 'idle' ? 'Restauração do banco' : state.title;
        message.textContent = state.status === 'idle' ? 'Nenhuma restauração em andamento.' : state.message;
        list.replaceChildren(
            ...state.steps.map((step) => {
                const item = document.createElement('li');
                item.dataset.step = step.key;
                item.dataset.state = step.state;
                item.className = 'flex items-center justify-between gap-4 px-4 py-3';

                const label = document.createElement('span');
                label.className = 'text-ink';
                label.textContent = step.label;

                const marker = document.createElement('span');
                marker.dataset.marker = '';
                marker.className = `font-mono text-xs ${tones[step.state] ?? tones.pending}`;
                marker.textContent = markers[step.state] ?? markers.pending;

                item.append(label, marker);

                return item;
            }),
        );
        back.classList.toggle('hidden', state.status === 'running');
    };

    const poll = async () => {
        try {
            const response = await fetch(stateUrl, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' });
            const state = await response.json();
            render(state);

            if (state.status === 'done' || state.status === 'idle') {
                // Dá um instante para ler o resultado e volta para a tela de Backup (que pode pedir login de novo).
                setTimeout(() => window.location.assign(doneUrl), state.status === 'done' ? 2500 : 0);

                return;
            }

            if (state.status === 'failed') {
                return;
            }
        } catch {
            // Os containers reiniciam durante a troca: uma falha de rede aqui é esperada, tenta de novo.
        }

        setTimeout(poll, 2000);
    };

    poll();
}
