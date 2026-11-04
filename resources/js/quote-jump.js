// Clicar no bloco citado rola até a mensagem original e a destaca por um instante. Sem fazer nada quando a original não
// está na página (o transcript é paginado): nesse caso o bloco só mostra o trecho guardado.

document.addEventListener('click', (event) => {
    const quote = event.target.closest('[data-quote-target]');
    const original = quote?.dataset.quoteTarget ? document.getElementById(`message-${quote.dataset.quoteTarget}`) : null;

    if (!original) {
        return;
    }

    original.scrollIntoView({ block: 'center', behavior: 'smooth' });
    original.classList.add('rounded-xl', 'ring-2', 'ring-accent');
    setTimeout(() => original.classList.remove('ring-2', 'ring-accent'), 1500);
});
