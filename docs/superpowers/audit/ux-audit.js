// Auditoria de UX: cole o CORPO desta função em `evaluate_script` (Chrome DevTools MCP) numa página já logada do app.
//
// Para cada rota, largura e tema, abre a tela num iframe da mesma origem e mede: rolagem horizontal do documento, controles
// sem nome acessível, alvos menores que 24 px, imagens sem `alt` e quantidade de `h1`. Devolve só o que não está limpo.
// Rodar antes e depois das correções e comparar. O editor de automação (`automacao/{id}`) em 360 px é ferramenta de desktop:
// o `ox` dele é conhecido.
//
// Rodar com dado realista (20+ tickets e contatos, nomes de 60+ caracteres, etiquetas e setores): com 1 ticket os estouros não
// aparecem. Cada tamanho é [largura, altura] do iframe (360×800, 720p, 1080p, 2K); `rolaV` marca rolagem vertical do documento
// nas telas de altura fixa (`FIXAS`), que têm rolagem interna e não devem rolar a página.
//
// Uso: ajuste `routes` (troque `automacao/2` por um id que exista) e, se quiser, `sizes`.
async () => {
    const routes = ['inbox', 'contatos', 'historico', 'relatorios', 'auditoria', 'automacao', 'automacao/2', 'configuracoes/setores', 'configuracoes/horario', 'configuracoes/respostas-rapidas', 'configuracoes/etiquetas', 'configuracoes/usuarios', 'configuracoes/canais', 'configuracoes/google', 'configuracoes/empresa', 'configuracoes/retencao'];
    const sizes = [[360, 800], [1280, 720], [1920, 1080], [2560, 1440]];
    const FIXAS = ['inbox', 'historico', 'auditoria'];
    const themes = ['dark', 'light'];
    const found = [];

    const probe = (doc) => {
        const win = doc.defaultView;
        const root = doc.documentElement;
        const cw = root.clientWidth;
        const visible = (e) => {
            const r = e.getBoundingClientRect();
            const s = win.getComputedStyle(e);

            return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
        };
        const named = (e) => (e.getAttribute('aria-label') || e.getAttribute('aria-labelledby') || e.getAttribute('title') || e.textContent || '').trim() !== '' || (e.id && doc.querySelector('label[for="' + e.id + '"]')) || e.closest('label');
        const controls = [...doc.querySelectorAll('button,a[href],[role=button],input:not([type=hidden]),select,textarea')].filter(visible).filter((e) => e.getAttribute('aria-hidden') !== 'true' && !e.classList.contains('sr-only'));
        const label = (e) => e.tagName.toLowerCase() + (e.getAttribute('aria-label') ? '[' + e.getAttribute('aria-label') + ']' : '') + (e.placeholder ? '{' + e.placeholder.slice(0, 30) + '}' : '');

        return {
            ox: root.scrollWidth > cw + 1 ? root.scrollWidth - cw : 0,
            semNome: controls.filter((e) => !named(e)).map(label),
            pequenos: controls.filter((e) => { const r = e.getBoundingClientRect(); return (r.width < 24 || r.height < 24) && e.type !== 'radio' && e.type !== 'checkbox' && !e.classList.contains('sr-only'); }).map(label),
            semAlt: [...doc.querySelectorAll('img')].filter((i) => !i.hasAttribute('alt')).length,
            h1: doc.querySelectorAll('h1').length,
            docH: root.scrollHeight,
            tema: root.classList.contains('dark') ? 'dark' : 'light',
        };
    };

    for (const theme of themes) {
        localStorage.setItem('flux.appearance', theme);

        for (const route of routes) {
            for (const [width, height] of sizes) {
                const frame = document.createElement('iframe');
                frame.style.cssText = 'position:fixed;left:0;top:0;border:0;opacity:0;pointer-events:none;width:' + width + 'px;height:' + height + 'px';
                document.body.appendChild(frame);
                await new Promise((done) => { frame.onload = done; frame.src = '/' + route; });
                await new Promise((done) => setTimeout(done, 700));

                try {
                    const m = probe(frame.contentDocument);
                    m.rolaV = FIXAS.includes(route) && m.docH > height + 1;

                    if (m.ox > 0 || m.semNome.length > 0 || m.pequenos.length > 0 || m.semAlt > 0 || m.h1 !== 1 || m.tema !== theme || m.rolaV) {
                        found.push({ route, width, height, theme, ...m });
                    }
                } catch (error) {
                    found.push({ route, width, theme, erro: String(error) });
                }

                frame.remove();
            }
        }
    }

    return found;
}
