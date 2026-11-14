# Animação dos painéis flutuantes e do compositor: design

Data: 2026-11-14. Tamanho: **mudança pequena** (design curto). Origem: análise com `find-animation-opportunities` e planos em
`plans/001` a `plans/003` (local, não versionados; este documento os substitui onde divergem).

## Intenção e porquê

Hoje quase nada na inbox se move: menus, seletor de emoji e sino aparecem de uma vez, sem ligação com o botão que os abriu, e as faixas
de gravação/anexos empurram o campo de texto num salto. O objetivo é **consistência espacial** (o painel nasce do gatilho) e **evitar
mudança brusca** (o compositor cresce em vez de pular), sem deixar a inbox mais lenta.

## Objetivos

- Menu da mensagem, barra de reações, seletor de emoji e sino: entrada `opacity 0→1` + `scale .95→1` em 150ms, saída 100ms, curva
  `cubic-bezier(0.23, 1, 0.32, 1)`, `transform-origin` no gatilho. Nunca `scale(0)`.
- Faixas de gravação e prévia de áudio: abrem e fecham pela altura, 200ms.
- Bandeja de anexos: entra com fade + descida e **tenta** altura (experimento com queda definida, abaixo).

## Não-objetivos e anti-objetivos

- **Não animar:** fila e Histórico, bolhas e chegada de mensagens, atalhos de teclado, indicador "digitando", SLA, `flux:dropdown`, toast.
- **"Respondendo a" não anima** (decisão do dono): o `Alt+P` alterna o bloco várias vezes por dia e deve continuar instantâneo.
- Fracasso mesmo que "funcione": menu que pisca invisível, painel que perde foco ou leitor de tela, lista de mensagens que pula quando o
  compositor cresce, build CSP quebrado.
- Não alterar `--ease-out` (`cubic-bezier(0.22, 1, 0.36, 1)`) nem `--duration-base` de `app.css:37-38`.

## Restrições

- Livewire 4.4 + Flux 2 + Alpine **build CSP**: expressões de atributo só no subconjunto de `tests/Unit/AlpineCspSubsetTest.php`; lógica
  em `resources/js`. `wire:transition` do Livewire 4 só faz crossfade (sem escala/origem): **não serve** aqui.
- `prefers-reduced-motion` já zera as transições globalmente (`app.css:123-131`).
- Mudar Blade de `resources/views/livewire/inbox/` exige completar o impacto com `grep -rn` (Livewire não gera arestas no CodeGraph).

## Abordagens

1. **Painéis flutuantes.** Única saída sensata: `x-transition` do Alpine com classes Tailwind. O menu da mensagem é caso especial
   (abre invisível, é medido e só então posicionado em `message-menu.js`): a entrada passa a ser guiada por um estado `placed` ligado
   depois do `place()`, com origem `top|bottom right` conforme abra abaixo ou acima; só a saída usa `x-transition:leave`.
   Origens: sino `top left` (abre abaixo, `left = rect.left`), emoji `bottom left`, reações `right` se `$out` senão `left`.
2. **Altura das faixas.** *Escolhida: `x-collapse`* (plugin do Alpine presente no bundle CSP do Livewire, sem dependência nova), uma
   linha por faixa, `x-collapse.duration.200ms`. Descartada por ora: grid `0fr→1fr` (curva exata e interrompível, mas reestrutura o HTML).
   **Troca prevista:** se a curva padrão do plugin não agradar no teste no navegador, usar o grid do `plans/002`.
3. **Bandeja de anexos (renderizada pelo servidor).** *Escolhido pelo dono: tentar altura.* O `x-collapse` só dispara em mudança de
   `x-show`, não na inserção; então o `<ul>` ganha estado local (`x-data` com `shown` false que vira true no `x-init` via `$nextTick`) para o
   `x-collapse` rodar na entrada. **Queda:** se o morph do Livewire atrapalhar (pisca, reinicia ao adicionar o 2º arquivo), voltar a
   fade + descida por `@starting-style` (`starting:opacity-0 starting:-translate-y-1`, 200ms). A saída segue instantânea nos dois casos.
   Margem `mb-2` vai para dentro do elemento animado, senão sobra vão de 8px quando fechado.

## Decisões do dono

- Escopo: planos 001 + 002 + 003. "Respondendo a" sem animação. `x-collapse` para as faixas. Anexos tentam altura.
- Validação visual: **o dono testa pelo Chrome DevTools / Claude in Chrome** (eu abro a conversa, gravo e mostro). Feel check do plano:
  origem do painel, 10% de velocidade, reduced motion, e a última mensagem visível enquanto o compositor cresce.

## O que fica para o construtor

- Detalhes exatos de classes e atributos (nos `plans/`); nome do estado `placed`; testes: ajustar qualquer assert que dependa de
  `x-show` ou `x-transition.opacity` (sino, faixas de voz) e rodar `AlpineCspSubsetTest`, `InboxVoiceTest`, `InboxAttachmentsTest`.
- **Custo se errado:** animação que incomoda é reversível removendo classes/atributos (sem migração, sem dado). O risco real é o menu da
  mensagem ficar mal posicionado (coberto por feel check) e `x-collapse` sob CSP não rodar (queda: grid do `plans/002`).

## Fora desta fatia

Animação de listas e mensagens, estado vazio/sucesso (item 7 da análise, prazer), feedback de pressão global nos botões (item 5, decisão de gosto).
