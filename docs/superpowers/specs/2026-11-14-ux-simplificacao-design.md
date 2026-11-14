# UX: menos informação na tela e controles no lugar certo

**Data:** 14/11/2026
**Origem:** auditoria `docs/ux-audit/2026-11-14-ux-audit.md` (skill `ux-audit-rethink`) e três rodadas de escolha com o dono.

## Intenção

O dono quer que o app mostre menos coisa ao mesmo tempo, peça menos cliques e deixe cada controle onde se espera. Quem mais
ganha é a atendente, que passa o dia na Fila e na Conversa; o supervisor ganha acesso direto à Automação.

## Objetivos

- Tirar informação repetida (mesmo dado em dois lugares da mesma tela, ou igual em todos os cards).
- Esconder o que se usa pouco atrás de um clique (filtros, ações secundárias, blocos longos), sem tirar o recurso.
- Legibilidade mínima: texto ≥ 12 px e alvo de clique ≥ 24×24 px (WCAG 2.2 AA, 2.5.8).
- Erro tolerável: remoções leves com "Desfazer"; destrutivas com modal do próprio app.

## Fora do escopo

- Nome do canal "Motorola Edge 60": é dado do beta; em produção o canal terá o número. Não muda código.
- Tema, cores, marca, tipografia além do piso de tamanho.
- Sino de alertas: entra como **correção de bug à parte** (`systematic-debugging`), não nesta spec. Escolha do dono: sem
  repetição, sem alerta de recuperação com zero mensagens, clique em alerta de SLA abre o ticket.

## Anti-objetivos (falha mesmo funcionando)

- Esconder algo que a atendente usa várias vezes por hora atrás de mais de um clique.
- Perder informação que hoje só existe no bloco removido (ex.: "Aberto em" sumir de vez).
- Quebrar o layout em 360 px, 720p, 1080p ou 2K, ou nos temas claro e escuro.
- "Desfazer" que não desfaz de verdade (ex.: etiqueta volta mas a auditoria fica sem registro).

## Abordagens consideradas

1. **Fatias por área, cada uma com plano, branch e PR (escolhida pelo dono).** Revisão e teste no beta por partes; erro em uma
   não segura as outras.
2. Um branch só com tudo: rápido de abrir, difícil de revisar e de reverter.
3. Um PR por item (12): controle máximo, mas multiplica os portões por 12.

## Decisões do dono, por fatia

Ordem: **B → A → C → D → E → F → G → H** (e o sino, à parte, no fim) (B primeiro porque é a tela de uso contínuo). Cada fatia tem plano próprio em
`docs/superpowers/plans/` e PR próprio; o dono autorizou ir até o PR.

### Fatia B: Fila e Conversa

1. **Painel da direita enxuto.**
   - Sai o bloco "Ticket" (Canal, Setor, Responsável, Aberto em). Canal e protocolo continuam na linha de baixo do cabeçalho;
     setor e responsável continuam no card da Fila. **"Aberto em"** vai para o `title`/tooltip do protocolo no cabeçalho
     (chamada minha: é o único dado que só existia ali).
   - "Etiquetas da conversa" e "Etiquetas" do contato viram **um bloco "Etiquetas"** com duas linhas rotuladas
     ("Conversa:" e "Contato:"), cada uma com seu seletor. A regra de negócio de cada lista não muda.
   - Bloco Contato: nome, empresa · cargo, e-mail e "Editar". O telefone sai daqui (já está no cabeçalho).
   - "Observações" e "Anteriores (N)" vêm **recolhidos** por padrão, com a contagem no título.
2. **Card da Fila sem o repetido.** O atendente some na visão "Meus"; o setor some quando todos os cards da lista atual são do
   mesmo setor. O resto do card não muda.
3. **Cabeçalho da conversa.** "Encerrar" continua visível; "Transferir" e "Reatribuir" vão para um menu "⋯" (`flux:dropdown`).
   Atalhos e permissões de cada ação não mudam.
4. **Busca na Fila.** Ícone de lupa ao lado de "Nova conversa"; ao clicar vira campo (nome, telefone ou protocolo), com "✕"
   para fechar e limpar. Filtra a lista da visão e da etiqueta atuais. Esc fecha.
4b. **Seletor de etiquetas com busca** (componente próprio, ver "Componentes"): substitui o `flux:select` nativo de
   "Adicionar etiqueta…" (conversa e contato) e o filtro "Todas as etiquetas" da Fila e de Contatos.
5. **Fila sem ticket aberto.** No lugar de "Selecione um ticket.": contagem de Aguardando e Meus, e o ticket com SLA mais perto
   de estourar (link que abre o ticket); sem ticket em risco, só "Escolha uma conversa à esquerda." A faixa vazia da direita
   some até abrir um ticket.

### Fatia A: barra lateral e legibilidade

6. **Automação na barra lateral**, só para admin e supervisor, entre Relatórios e Auditoria; sai do menu de Configuração.
   A rota não muda.
7. **Piso de tamanhos no app todo.** Nenhum `text-[10px]`/`text-[11px]` (hoje 52 usos); mínimo `text-xs` (12 px). Alvos de
   clique mínimos 24×24 (botão "Ações da mensagem", protocolos dos Anteriores, "Ver todos no Histórico"). Rótulos de seção em
   monoespaçada caixa-alta viram texto normal em negrito, 12 px. Um teste-guarda proíbe `text-[10px]`/`text-[11px]` nas views
   (no molde do `ThemeTokensTest`).

### Fatia C: Histórico e Contatos

8. **Histórico:** fica só a busca; De, Até, Setor, Motivo e Atendente vão para um botão "Filtros" (abre o **popover próprio**,
   ver "Componentes", com "Limpar" e "Aplicar") com a contagem de filtros ativos. Os padrões de hoje (ex.: "De" 30 dias atrás) continuam e **não contam** como ativos.
9. **Contatos:** a lista ocupa a largura; a ficha abre em gaveta lateral (`flux:modal` variante flyout) ao clicar e fecha com Esc
   ou "✕". Mesmo comportamento no celular. "Conversar" na linha continua funcionando sem abrir a ficha.

### Fatia D: confirmações e "Desfazer"

10. As 19 `wire:confirm` (confirm nativo do navegador) se dividem em:
    - **Leves, com Desfazer** (ação acontece na hora; toast `Flux::toast` com ação "Desfazer" por ~6 s): remover etiqueta da
      conversa ou do contato, apagar nota, remover foto do contato. O desfazer restaura o estado anterior e registra na
      auditoria como evento próprio.
    - **Graves, com modal do app** (`flux:modal` com o mesmo texto de hoje e botão vermelho): apagar mensagem para todos, apagar
      anexos antigos, apagar setor, etiqueta (cadastro), workflow, resposta rápida, feriado; desconectar Google; importar
      histórico; tomar ticket de outro atendente; copiar versão para o rascunho; remover logotipo; reenviar mensagem incerta.
    - O inventário exato sai no plano da fatia D, a partir de `grep wire:confirm resources/views`; item que não couber em
      nenhuma das listas vai ao dono.

### Fatia E: editor do bot

11. **"Inserir variável"** nos campos de texto do bot: botão que lista as variáveis aceitas pelo `Vars` e insere no cursor.
    Digitar à mão continua valendo.
12. **Zoom legível ao abrir:** ao abrir o editor e no "Centralizar", o zoom nunca fica abaixo de um mínimo em que o texto dos
    blocos se lê em 1280×720; se o fluxo não couber, rola. O valor mínimo sai de medição no plano da fatia E.

### Fatia F: gráfico nos Relatórios

13. "Volume por setor" passa a usar o **gráfico de barras próprio** (ver "Componentes"): SVG, legenda, dica ao passar o mouse
    com o número exato, e tabela equivalente para leitor de tela. Os números e o período não mudam.

### Fatia G: busca de ações (Ctrl+K)

14. **Ctrl+K** (e ⌘K no Mac, e um botão de busca na barra lateral) abre uma busca em modal: telas que o papel do usuário pode
    abrir, contatos (nome/telefone) e protocolos de ticket. Enter abre; setas navegam; Esc fecha. Respeita permissões e setores
    (quem não vê um ticket não acha o protocolo dele). Lista vazia mostra "Nada encontrado".

### Fatia H: faixas de aviso do topo dispensáveis (pedido do dono em 14/11, depois da aprovação das fatias A a G)

15. Cada faixa fixa do topo (Sem conexão com o servidor, WhatsApp desconectado, SLA estourado, Som desligado neste navegador) ganha um
    "✕" (`aria-label="Dispensar aviso"`, alvo ≥ 24 px) que a **esconde por um tempo** e ela volta sozinha; não é fechar de vez. O dono
    delegou os tempos ("dada a importância de cada barra"); **minha proposta, a confirmar**:

    | Faixa | Some por | Volta antes se |
    |---|---|---|
    | Sem conexão com o servidor | 1 min | a conexão cai de novo depois de voltar |
    | WhatsApp desconectado | 2 min | outro canal cai, ou o canal volta e cai de novo |
    | SLA estourado | 3 min | o número de tickets estourados aumenta |
    | Som desligado neste navegador | até a próxima visita (sessão do navegador) | nunca no mesmo acesso |

    - Fica **no navegador** (`localStorage` para os minutos, `sessionStorage` para "próxima visita"): dispensar no celular não esconde no
      desktop, e cada pessoa decide por si. Sem persistência no servidor nem nova tabela.
    - A regra "a dispensa vale?" é uma função pura (`isDismissed(entry, now, signature)`) com testes `node --test`; `signature` é o que muda
      quando o problema piora (ex.: lista de canais fora do ar, contagem de SLA).
    - Os botões "Silenciar som 15 min / 1 h" da faixa do WhatsApp continuam como estão (são sobre o som, não sobre a faixa).
    - Quem vê a faixa hoje (papel e permissão) continua vendo e pode dispensá-la; nada novo é exibido.
    - Anti-objetivo: o aviso crítico sumir sem voltar. Toda dispensa tem prazo, e o estado "fora do ar" real continua visível no sino e
      na Fila (ponto e selo).
    - **Fora do escopo:** o dono prevê, mais adiante, uma parte mobile separada do aplicativo de desktop; esta fatia só corrige o peso das
      faixas empilhadas (hoje quatro ocupam a tela do celular inteira) e vale para os dois.

## Componentes: Flux grátis x Pro

Conferido em 14/11/2026 no site (fluxui.dev) e no pacote instalado (`livewire/flux` 2.21.0, a versão mais nova).

**Grátis e instalados, a usar nesta rodada:** `flux:tooltip` (hoje sem nenhum uso; vai no "Aberto em" do protocolo e nos
botões só de ícone), `flux:modal` variante flyout (gaveta de Contatos), `flux:toast` com `action` (Desfazer), `flux:dropdown`
+ `flux:menu` (menu "⋯" do cabeçalho). Outros grátis sem uso hoje (`flux:card`, `flux:table` com ordenação, `flux:pagination`,
`flux:progress`, `flux:toggle`) ficam fora desta rodada.

**Pro (não temos), e o que fazemos no lugar com código próprio, só inspirado no Pro:**

| Pro | Já temos | Novo nesta rodada |
|---|---|---|
| Accordion | `x-disclosure` | só o rótulo (fatia A) |
| Tabs | `x-audit-tabs`, radio segmentado da Fila | nada |
| Pillbox + Select com busca | — | `x-tag-picker` (fatia B): busca sem diferenciar acento, cor da etiqueta, teclado (setas, Enter, Esc), `role="combobox"`/`listbox` |
| Popover | — | `x-popover` (fatia C): ancorado no botão, fecha com Esc e clique fora, foco vai para dentro ao abrir e volta ao botão ao fechar. Primeiro testar se o `flux:dropdown` grátis aceita conteúdo livre; se não, `popover` nativo do HTML + Alpine |
| Chart | — | `x-bar-chart` (fatia F): SVG puro, sem biblioteca |
| Command | — | `x-command-palette` (fatia G): modal com busca e lista navegável por teclado |
| Timeline, Calendar, Date picker, Kanban, Editor, File upload, Context menu, Slider, Time picker, Autocomplete | — | nada nesta rodada |

Regra: nenhum código copiado do Flux Pro (licença paga); só a aparência e o comportamento públicos do site servem de
referência. Componente próprio segue os tokens de cor existentes e as mesmas classes de foco do Flux.

## Restrições

- Laravel 13, Livewire 4.4, Flux 2.21 (sem dependência nova: toast com ação, modal flyout e dropdown já estão instalados).
- Verificação visual em 360 px, 1280×720, 1920×1080 e 2560×1440, claro e escuro, **com dados realistas** (≥ 20 tickets, nomes
  longos), como na auditoria de fechamento de 12/11.
- `Livewire`: mudar propriedade ou método público de componente exige `grep` em `resources/views` (CLAUDE.md).
- Tokens de cor existentes; nada de cor nova.

## O que fica com quem implementa

Nomes de métodos, propriedades e componentes; texto exato de rótulos não citados acima; o limiar de "mesmo setor" quando a lista
está vazia; ícones.
