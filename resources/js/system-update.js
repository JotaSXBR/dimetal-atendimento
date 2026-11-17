// Tela Sistema: no reinício da atualização o app some por alguns minutos (502, 404 ou sem rede). O `wire:poll` da tela segue
// tentando; aqui só se troca o erro do Livewire por um aviso calmo, que some quando uma resposta volta.
const lost = () => document.querySelector('[data-update-lost]');

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ succeed, fail }) => {
        if (!document.querySelector('[data-system-update]')) {
            return;
        }

        succeed(() => lost()?.setAttribute('hidden', ''));
        fail((failure) => {
            failure?.preventDefault?.();
            lost()?.removeAttribute('hidden');
        });
    });
});
