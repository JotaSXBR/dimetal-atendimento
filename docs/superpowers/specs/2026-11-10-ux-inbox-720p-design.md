# UX fatia 4a: Inbox legível e alinhado em 720p

Data: 2026-11-10. Só o beta está em escopo. Segue a fatia 3 (`2026-11-07-ux-inbox-fatia-3-design.md`) e a sidebar recolhível
(`2026-11-10-sidebar-recolhivel-design.md`). Laravel 13, Livewire 4, Flux 2.21 (grátis), Tailwind 4.

## Por que

O dono dá prioridade ao Inbox em 1280x720. A sidebar recolhida ajudou na largura, mas falta afinar a **altura**, o recurso escasso
nessa resolução. Medido no navegador (1280x720, sidebar fechada, os dois avisos do topo visíveis):

- avisos do topo ("Som desligado" e "SLA estourado"): ~97px, ~48px cada;
- cabeçalho da conversa: ~145px, porque as ações (Transferir, Reatribuir, Encerrar) descem para uma segunda linha mesmo com ~600px;
- compositor: ~100px (linha do modo Responder/Nota + linha da caixa de texto);
- sobram ~370px para as mensagens; o painel de detalhes (280px) corta "Anteriores" e os botões do contato no fim.

## Metas

Em 1280x720 com a sidebar fechada e os dois avisos visíveis, a **área de mensagens passa de ~370px para pelo menos 480px**, sem perder
nenhuma informação ou ação, e o layout continua alinhado em 1080p, 2K e celular.

1. **Avisos do topo mais baixos** (`components/alerts-local`, `livewire/alerts/banner`): uma linha cada, `py-1.5`, texto `text-xs`, botões
   pequenos; o texto longo trunca com `title` e `sr-only` completo; "Silenciar som 15 min/1 h" e "Ver canais" continuam, mais compactos.
   Alvo: ~32px cada (de ~48px). Os papéis ARIA (`role="alert"`, `role="status"`, `aria-live`) e os `data-alert` não mudam.
2. **Cabeçalho da conversa responsivo à largura da coluna, não da janela** (`livewire/inbox/inbox`): a coluna da conversa vira
   `@container` (Tailwind 4). Linha 1: nome + selo de status + SLA curto ("Responder até 11h18" / "SLA estourado"); linha 2: protocolo ·
   telefone · canal (trunca). As ações ficam à direita da linha 1 e, abaixo de `@2xl` (42rem), **Transferir e Reatribuir viram só ícone**
   (com `tooltip`, `aria-label` e nome acessível); **Encerrar** e **Assumir** mantêm o texto. Alvo: cabeçalho ≤ 96px em 720p.
3. **Compositor mais baixo**: em coluna `@xl` (36rem) ou mais, modo (Responder/Nota privada), anexos/áudio/assinatura, caixa de texto e
   enviar ficam **numa linha só**; abaixo disso, duas linhas como hoje. A caixa cresce até 5 linhas.
4. **Painel de detalhes sem corte**: rodapé fixo (`sticky bottom-0`) com "Conversar" e "Editar contato", e a rolagem só nos blocos; "Anteriores"
   continua recolhível.
5. **Cabeçalho mais denso em 720p sem mudar 1080p/2K**: tudo acima usa variantes de container/altura, então telas maiores só ganham respiro.

## Não metas

- Funcionalidade nova, mudança no tempo real (Reverb), em SLA, portões `can:*` ou na lista da Fila (já no desenho aprovado).
- Trocar os selos, botões, estados vazios ou esqueletos: é a fatia 4b.
- Esconder o painel de detalhes por padrão (o dono já o abre e fecha com o botão "i").

## Anti-metas

- Perder o aviso de conexão ou de SLA, ou deixá-lo ilegível; ação sem nome acessível; alvo de clique menor que 32px em ícone;
  texto cortado sem `title`; coluna desalinhada ou rolagem horizontal em 1280, 1920, 2560 ou 360px; contraste abaixo de AA nos dois temas.

## Restrições e pesquisa

- Tailwind 4: `@container` na coluna e variantes `@2xl:`, `@xl:`, `@max-*:` (padrões em rem: `@sm` 24, `@md` 28, `@xl` 36, `@2xl` 42);
  o container não estiliza a si mesmo, então a variante vai nos filhos. `motion-safe:` para qualquer animação.
- Flux 2.21: `flux:button` aceita `tooltip`, `icon`, `size="xs|sm"` e `variant`; ícone só ganha botão quadrado automático, mas **não ganha
  `aria-label`** sozinho (adicionar). O tooltip só aparece com mouse: em toque o nome acessível e a ação precisam bastar.
- Context7 do projeto estava com a chave antiga; a pesquisa saiu das páginas oficiais (Flux, Livewire 4, Tailwind 4, Laravel 13). Reiniciar a
  sessão ativa o Context7 global (OAuth) para as próximas fatias.
- Buscas em `resources/views` por `grep` no Bash (CodeGraph não indexa Blade). Scripts Python que gravam Blade: string raw (`r'...'`); um
  único bloco `@php ... @endphp` por arquivo grande (nunca `@php(...)` misturado com bloco).
- Testes: marcação (aria-label, classes de container, `data-alert`) e comportamento Livewire existente; verificação visual no **Claude in Chrome**
  em 1280x720 (aberta e fechada), 1920x1080, 2560x1440 e 360px, claro e escuro, medindo alturas com `getBoundingClientRect`.

## Abordagens consideradas

- **Container queries no cabeçalho e no compositor** (escolhida): a coluna da conversa muda de largura com a sidebar aberta/fechada e com o
  painel de detalhes, então a quebra certa depende da coluna, não da janela. Tailwind 4 já traz o recurso, sem dependência.
- **Breakpoints de viewport (`lg:`/`xl:`)**: não distinguem sidebar aberta de fechada (a mesma janela dá colunas de 400 e 600px).
- **Esconder avisos e ações em um menu "Mais"**: ganha altura, mas esconde ação e aviso crítico; contra o objetivo.

## Deixado ao builder

Classes e tamanhos exatos; o texto curto do SLA no cabeçalho; como tornar a caixa de texto auto-crescente sem quebrar `composerMode`/`typingPresence`
(`resources/js/composer-mode.js`); se o rodapé do painel de detalhes usa `sticky` ou `flex` com `min-h-0`.

## Entrega

PR 1 = branch `fix/ux-menores-fatia-3-e-sidebar` (menores da fatia 3 e da sidebar) **mais esta 4a**, no mesmo branch. Todo achado de revisão (inclusive
menores) é corrigido antes do PR; os 3 menores novos da sidebar (ordem do Tab, texto redundante do ponto e `badge:aria-label`, fallback `'Laravel'` do rótulo
do logo) entram aqui.
