# UX, fechamento da v1: defeitos de tema claro e de 720p/1080p/2K

Data: 2026-11-12. Só o beta está em escopo. Design curto (mudança pequena: dois ajustes de CSS e uma regra de teste).

## Por que

O dono quer fechar a UX antes de seguir para backup, exportação, 2FA obrigatório e CSP. Pediu inspeção do tema claro e das
resoluções 720p, 1080p e 2K, localmente, com o `docs/superpowers/audit/ux-audit.js`. Não é redesenho: o layout final o dono
refaz depois com outras skills. Automações (visual) e CSP ficam fora (decisão do dono, 12/11).

## O que a auditoria achou

Servidor local (`dimetal_local`), populado com 20 tickets, nomes longos, etiquetas e setores variados (a auditoria anterior rodou
com 1 ticket e não via os defeitos abaixo). 15 telas × 360, 1280×720, 1920×1080 e 2560×1440 × claro e escuro, mais inspeção
visual de Contatos, Fila (com e sem conversa), Setores e Relatórios em claro e do Inbox em 2K.

1. **Contatos em 1280×720: rolagem horizontal de 125 px, painel de detalhe cortado** (claro e escuro).
   Causa: `grid-cols-[1fr_22rem]` em `resources/views/livewire/contacts/index.blade.php:1`; `1fr` vale `minmax(auto,1fr)` e a
   coluna da lista cresce até o conteúdo (nomes longos). Correção: `minmax(0,1fr)`.
2. **Fila: o documento rola ~950 px de branco, em todas as resoluções, com 20 tickets.**
   Causa: a lista é `overflow-y-auto` mas não `relative` (`inbox.blade.php:57`); os `span.sr-only` (position absolute) dos
   cartões têm como bloco de contenção a janela e escapam do corte. Correção: `relative` na lista. Cobre também os 3 elementos
   fora da tela que o script apontou no Inbox em 360 px.
3. **21 px de rolagem vertical em 720p** em Configuração, Automação e Relatórios (documento 741 px, janela 720). Causa a
   investigar na execução (suspeita: `flux:main h-dvh` + faixa do aviso de som somados ao cabeçalho da sidebar).
4. **Tema claro:** sem defeito nas telas inspecionadas; contraste dos tokens já coberto por `ThemeTokensTest`.
5. **2K:** a Fila preenche bem; sem defeito de alinhamento. Texto das mensagens fica à esquerda numa coluna larga: é o layout
   atual, não defeito (o dono refaz o layout final).

Limite desta auditoria: Histórico, Auditoria, Canais, Horário, Empresa, Google, Etiquetas, Usuários e Respostas rápidas foram
medidos pelo script (sem estouro, sem controle sem nome) mas não vistos em captura de tela.

## Objetivos, não-objetivos, anti-objetivos

- Objetivo: nenhuma tela com rolagem horizontal, nem rolagem vertical de documento onde a tela é de altura fixa, em 360, 720p,
  1080p e 2K, nos dois temas, com dados realistas.
- Não-objetivo: redesenho, novo componente, mexer no editor de automação, CSP.
- Anti-objetivo: corrigir o sintoma com `overflow-x-hidden` no corpo (esconde o corte em vez de resolver).

## Abordagens

- **A (recomendada):** corrigir cada causa na origem (os dois ajustes de classe; investigar o item 3) e fixar com testes: um
  teste de renderização por tela que proíbe `grid-cols-[1fr_...]` sem `minmax(0,...)` e rolável sem `relative` seria frágil;
  em vez disso, ampliar o `ux-audit.js` com altura por resolução e `scrollHeight`, e rodá-lo antes e depois.
- B: `overflow-x-hidden` no `<main>`. Esconde o corte do painel de Contatos; rejeitada.
- C: mais capturas por tela antes de corrigir. Só se o dono pedir; o diagnóstico já tem causa.

## Decisões minhas (o dono pode reverter)

- O script passa a usar `[largura, altura]` (360×800, 1280×720, 1920×1080, 2560×1440) e a medir `scrollHeight`; custo se errado: nenhum.
- Teste de regressão em Feature/Livewire para Contatos e Fila verifica as classes (`minmax(0,1fr)`, `relative`), porque o ambiente de
  teste não mede layout; a prova de layout é o script no navegador.

## Prova de pronto

`ux-audit.js` local sem achado em Contatos e Fila (e sem os 21 px), nos dois temas; suíte e portão 7 verdes.
