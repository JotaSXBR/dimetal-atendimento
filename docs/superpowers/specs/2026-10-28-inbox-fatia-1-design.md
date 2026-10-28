# Inbox — fatia 1: receber, responder texto e assumir

Data: 2026-10-28. Complementa `2026-10-27-whatsapp-atendimento-design.md` (seções 9, 11, 12 e 15).
Desenho de tela: arquivo Pencil `Dimetal.pen`, telas **Inbox**, **Ticket encerrado** e **Config - Canais**.

## 1. Intenção e porquê

A Fase 0 provou que o WAHA conecta e que o painel envia texto de teste. Hoje o sistema só guarda o
evento bruto (`waha_events`) e atualiza o status do canal. Não existe conversa. A fatia 1 entrega o
primeiro momento em que a operação atende de verdade pelo painel: o cliente escreve no WhatsApp, a
conversa aparece na fila, um atendente assume, responde, e vê entrega e leitura da mensagem.

É o primeiro passo para deixar de depender do Cam Krolik. A fatia é pequena de propósito: cada passo
seguinte (encerrar/transferir, mídia, bot, horário) entra em fatias próprias.

## 2. Objetivos, não-objetivos e anti-objetivos

**Objetivos**
- Mensagem recebida vira contato + ticket + mensagem, sem duplicar quando o WAHA reenvia.
- Mensagem enviada pelo celular (fora do painel) entra no histórico marcada como tal.
- Fila geral sem setor; atendente assume; responde texto; ticks de enviado, entregue e lido.
- Atualização em tempo real da lista e da conversa, sem recarregar a página.
- Vários números de WhatsApp (o beta começa com 2); cada ticket e mensagem sabem de qual número vieram.

**Não-objetivos (ficam para fatias seguintes)**
- Transferir, protocolo formal `2026-000123`, resumo e motivo de encerramento, ticket vinculado,
  fechamento por inatividade. (O **Encerrar mínimo** entra: ver 5.2.)
- Mídia de qualquer tipo, figurinha, áudio, reação, edição e revogação de mensagem.
- Bot, fluxo, setor automático, horário de funcionamento, SLA, tags, notas, busca, respostas rápidas.
- Backfill de histórico.

**Anti-objetivos (falha mesmo que "funcione")**
- Duplicar mensagem ou ticket quando o webhook é reenviado ou quando o envio pelo painel volta como eco.
- Atribuir a mesma conversa a dois atendentes por disputa simultânea.
- Perder uma mensagem recebida porque o job falhou no meio (o evento bruto continua sendo a fonte).
- Reescrever ou apagar mensagem pela interface.

## 3. Restrições

- Laravel 13, Livewire 4, Flux, PostgreSQL 18, Redis 8, WAHA 2026.10.1 GOWS. Deploy no Coolify, com
  deploy automático após testes na `main`.
- Os dois PRs passam em `composer ci:check` (Pint, PHPStan nível 6, testes) antes de abrir.
- Mensagens e eventos são append-only: nenhuma rota de edição ou exclusão, nem para admin.
- `AGENTS.md`: pesquisar projetos open source com mais de 1000 estrelas antes de implementar o que for
  desconhecido (feito para Reverb e para o modelo de conversa; registrado na seção 6).
- O arquivo Pencil é a referência visual. Divergir dele exige avisar.

## 4. Abordagens consideradas

| Abordagem | Trade-off |
|---|---|
| Um PR só (dados, ingestão, canais, inbox, Reverb) | Menos cerimônia, mas diff grande e difícil de validar no beta |
| **Dois PRs: A (dados + ingestão + canais múltiplos), B (inbox + Reverb)** | Escolhida. O risco do WAHA (eventos, LID, eco) fica isolado da interface e é provável olhando o banco |
| Tela primeiro com dados falsos | Mostra o visual cedo, mas a ligação ao real refaz trabalho |

Decisões do dono do produto nesta rodada: ticket novo entra numa **fila geral sem setor**; tempo
real com **Laravel Reverb**; **vários números** (2 no começo); escopo da fatia = **receber, responder
texto e assumir**.

## 5. Desenho

### 5.1 PR A — dados, ingestão e canais múltiplos (sem tela nova de inbox)

**Tabelas** (todas com timestamps; chaves e índices como abaixo)
- `contacts`: `id`, `wa_id` (telefone no formato `<dígitos>@c.us`, único), `lid` (nullable, único),
  `name` (nome do WhatsApp, até 120 caracteres), `nickname` (nullable).
- `tickets`: `id`, `contact_id`, `channel_id`, `sector_id` (nullable), `assigned_user_id` (nullable),
  `status` (`aguardando | em_atendimento | encerrado`; só os dois primeiros são usados nesta fatia),
  `opened_at`, `first_human_reply_at`, `last_inbound_at`, `last_outbound_at`, `closed_at`.
  Índice parcial garantindo **no máximo um ticket aberto por contato e canal**.
- `messages` (append-only): `id`, `ticket_id`, `channel_id`, `contact_id`, `wa_message_id`
  (**único por canal**), `direction` (`in | out`), `type` (`text` agora; demais tipos reservados),
  `body`, `origin` (`contato | painel | fora_do_painel`), `author_user_id` (nullable), `sent_at`,
  `delivery_status` (`sent | delivered | read | failed`, nullable), `delivered_at`, `read_at`.
- `ticket_events` (append-only): `ticket_id`, `type` (`opened`, `claimed`, `message_in`, `message_out`),
  `actor_user_id` (nullable), `payload` json, `created_at`.

**Ingestão** (`HandleWahaEvent`, estendido; o evento bruto continua sendo gravado antes de tudo):
- `message` (recebida) e `message.any` com `fromMe` (enviada por outro aparelho): resolve o contato,
  acha ou abre o ticket, grava a mensagem em transação. Deduplica por `wa_message_id` + canal.
- `fromMe` que coincide com uma mensagem já gravada pelo painel (mesmo `wa_message_id`) **não** cria
  segunda linha nem vira "fora do painel".
- `message.ack`: atualiza `delivery_status`, `delivered_at`, `read_at` (códigos WAHA: 1 enviado,
  2 entregue, 3 e 4 lido, -1 falhou; 0 e desconhecidos ignorados). Ack que chega antes da mensagem
  é guardado e reaplicado quando ela existir (ou descartado com log, se não houver espera possível).
- Mensagem de tipo não texto: grava o ticket e uma mensagem `type` correspondente com `body` vazio e
  o evento bruto como fonte; **não baixa mídia nesta fatia**.
- Mensagem de grupo (`@g.us`) e status/broadcast: ignoradas e logadas.

**Identidade**: o WhatsApp entrega parte dos remetentes como `@lid`. Regra reaproveitada do crmv2
(`waha-payloads.ts`, testada em `waha-lid.test.ts`): se o remetente é `@lid` e `_data.Info.SenderAlt` é
`<dígitos>@s.whatsapp.net` com 8 a 15 dígitos sem zero à esquerda, o telefone é essa identidade e o
`lid` fica guardado no contato; caso contrário o contato é identificado só pelo `lid`. Nome vindo do
remetente é cortado em 120 caracteres.

**Canais múltiplos**: a tela **Canais** deixa de usar `first()`. Lista um cartão por canal (QR, código de
pareamento, status, conectar/desconectar, texto de teste) e permite adicionar novo canal pelo nome da
sessão. O WAHA já suporta várias sessões; o webhook já resolve o canal pela sessão.

**Envio** (`WahaClient::sendText` já existe): passa a registrar a mensagem local (`origin=painel`,
`delivery_status=null`) com o `id` devolvido pelo WAHA, no mesmo caminho que a Inbox usará.

### 5.2 PR B — Inbox e tempo real

**Tela** (rota `inbox`, substitui o `dashboard` como página inicial para atendente e supervisor),
fiel ao Pencil: menu lateral, lista de tickets (abas Todos / Aguardando / Meus; blocos Aguardando e
Em atendimento), conversa (cabeçalho com contato, número e ação **Assumir**, mensagens com hora e
ticks, campo de resposta) e painel de detalhes (canal, setor, responsável, aberto em).
Mensagens "fora do painel" levam o selo do desenho. O que o desenho mostra e esta fatia **não**
entrega aparece oculto ou desabilitado (Transferir, Encerrar, SLA, tags, anexos, tickets anteriores).

**Regras**
- Fila geral: ticket `aguardando` sem setor é visível a qualquer atendente ou supervisor ativo.
- **Assumir**: `UPDATE ... WHERE assigned_user_id IS NULL` atômico; quem perde a corrida recebe aviso
  e a lista atualiza. Assumir muda o ticket para `em_atendimento` e grava `claimed`.
- **Encerrar mínimo** (decisão do dono): botão que define `status=encerrado` e `closed_at` e grava o
  evento `closed` com o ator. Sem resumo, motivo, protocolo nem ticket vinculado. Pode encerrar o
  responsável, o supervisor e o admin. Depois de encerrado, o ticket não recebe mais mensagens: uma
  mensagem nova do cliente (ou do celular) abre um ticket novo `aguardando`. O índice de unicidade
  considera aberto todo ticket com `status <> 'encerrado'`. Não há reabrir nesta fatia.
- Só o responsável responde; supervisor e admin podem responder qualquer ticket.
- Enviar: valida texto não vazio, envia pelo `WahaClient` por job na fila, marca `failed` com motivo
  visível se o WAHA recusar. Nunca envia direto da requisição web.
- Autorização por Policy; atendente vê só tickets aguardando e os próprios (regra da spec, seção 7).

**Tempo real**: Laravel Reverb. Eventos de broadcast privados por canal de usuário/fila
(`ticket.updated`, `message.created`, `message.status`). O Livewire assina via Echo e atualiza só o
trecho afetado. Novo serviço no Coolify (`reverb-beta`, mesma imagem, comando `reverb:start`) com
roteamento WebSocket; ver seção 7.

## 6. Pesquisa feita (AGENTS.md)

- **Chatwoot** (MIT): referência de modelo conversa/mensagem/atribuição e de `message.any`/ack com WAHA;
  não adotado porque o produto precisa do fluxo próprio da Dimetal.
- **crmv2** (autoria do dono): ingestão WAHA, regra de LID, mapa de ack, SSE de eventos de domínio.
  O LID e o mapa de ack são reaproveitados; o SSE não, pois o Laravel tem Reverb oficial.
- **Tercela** (open source, Hono/Bun): padrão publish/subscribe por tópico. Só como confirmação do
  desenho de broadcast; maturidade e licença não verificadas.
- **Laravel MCP** (`laravel/mcp`): serve para construir servidores que expõem o app a clientes de IA.
  Não encurta nem melhora o código da Inbox. **Descartado** nesta fatia; só reavaliar se um dia houver
  um recurso de IA consultando tickets.
- **Laravel Boost** (`laravel/boost`, dependência de desenvolvimento): traz guidelines e skills
  (Livewire 4, Flux, Pint, PHPUnit, Tailwind 4), ferramentas MCP (esquema e consulta do banco, logs,
  último erro) e busca semântica na documentação. O ganho é indireto, sobre o agente e não sobre o
  código; as guidelines custam em torno de 4 a 5 mil tokens fixos por sessão (estimativa a partir de
  arquivos públicos de 15 a 20 KB, não medida aqui); a busca de documentação se sobrepõe ao Context7 já
  em uso; e ele grava blocos no `AGENTS.md`/`CLAUDE.md`. **Confiança de utilidade abaixo de 90%, não
  adotado.** Reavaliar com um teste curto depois do PR A (instalar, medir `/context`, comparar).
- Reverb, Echo e a integração com Livewire seguem a documentação oficial do Laravel 13; a tarefa de
  instalação deve conferi-la antes de codar.

## 7. Infraestrutura e entrega

- Ordem: **PR 0** (docs + `fontaine`, já preparado), **PR A**, **PR B**. Cada um mergeia só com CI verde
  e vai ao beta pelo deploy automático.
- PR A: migrações aditivas; nenhuma tabela existente é alterada além de índices. Rollback = migrar para trás.
- PR B: novo Application `reverb-beta` no Coolify (alias interno, healthcheck próprio), variáveis
  `REVERB_*` e `BROADCAST_CONNECTION=reverb`, e o app e o worker passam a transmitir eventos. O
  `deploy-beta.yml` ganha o UUID do `reverb-beta`.
- Não há impacto no `main`: o trabalho segue só no beta.

## 8. Critérios de aceite

PR A
- Mensagem real de cliente cria contato, ticket `aguardando` e mensagem `in`; reenvio do mesmo webhook
  não duplica nada.
- Mensagem enviada pelo celular aparece como `fora_do_painel`; a enviada pelo painel e devolvida como
  eco **não** aparece duas vezes.
- Ack de entrega e leitura atualiza a mensagem certa.
- Remetente `@lid` com e sem `SenderAlt` válido gera o contato correto.
- Tela Canais mostra e opera 2 números independentes.
- Testes cobrem cada regra acima; `composer ci:check` passa.

PR B
- Cliente escreve; sem recarregar, o ticket aparece na fila de outro usuário logado.
- Dois atendentes clicam **Assumir** ao mesmo tempo: um assume, o outro vê aviso, nunca os dois.
- Resposta sai pelo WhatsApp e mostra um tick, depois dois, depois dois azuis conforme os acks.
- Falha do WAHA no envio aparece na mensagem como falha, sem perder o texto.
- **Encerrar** fecha o ticket e grava o evento; a mensagem seguinte do cliente abre um ticket novo
  `aguardando`, e nunca existem dois tickets abertos para o mesmo contato e número.
- Atendente não abre ticket de outro; supervisor abre qualquer um.
- Testes Livewire e de autorização passam; `composer ci:check` passa.

## 9. Decisões do builder (podem ser contestadas)

- **Papéis e "ativo"**: `users.role` (admin, supervisor, atendente) e `users.active` já existem; nenhuma
  migração nova. A policy checa os dois.
- **Eventos do WAHA**: a sessão já assina `message`, `message.any` e `message.ack`. `message` trata só
  mensagem recebida; `message.any` é lido apenas quando `fromMe`. Como `message.any` também dispara para
  recebidas, a deduplicação por `wa_message_id` + canal absorve a repetição.
- **Eco da mensagem do painel**: o painel grava a linha **antes** de chamar o WAHA (`origin=painel`,
  `wa_message_id` vazio, estado pendente) e preenche o id quando o WAHA responde. Se o eco `fromMe` chega
  antes disso, a ingestão procura uma linha pendente do mesmo canal e contato, com o mesmo texto, criada
  nos últimos 60 s, e completa o id nela em vez de criar outra. Sem correspondência, vira `fora_do_painel`.
- **`fromMe` sem ticket aberto** (atendente escreveu pelo celular primeiro): abre um ticket
  `em_atendimento` sem responsável, com `first_human_reply_at` preenchido, e não entra na fila de espera.
  Qualquer um pode assumi-lo.
- **Ack antes da mensagem**: o job do ack se reenfileira (`release`) a cada 5 s, até 6 vezes; depois
  descarta com log. Sem tabela de buffer.
- **Quem vê o quê**: atendente vê tickets `aguardando` e os próprios; supervisor e admin veem todos.
  "Todos" na tela significa "tudo o que o usuário pode ver".
- **Responder sem assumir**: ticket `aguardando` sem responsável exige **Assumir** antes de responder,
  inclusive para supervisor. Supervisor e admin podem responder tickets de outro responsável sem trocar o
  responsável. `first_human_reply_at` marca a primeira mensagem humana de saída do ticket.
- **Broadcast**: canal privado `inbox` (autorizado para usuário ativo com qualquer papel) e canal privado
  `users.{id}`. Os eventos levam só ids e tipo; o corpo da mensagem é buscado pelo Livewire depois, já
  sob a policy, então o canal nunca vaza texto.

## 10. Fica a cargo de quem for construir

Nomes de classes, estrutura de pastas, formato exato dos eventos de broadcast, estratégia de
paginação da lista, política de reaplicação do ack que chega antes da mensagem, e a escolha entre
Policy e Gate para a autorização, desde que cumpram as regras acima e sigam o padrão do código atual.
