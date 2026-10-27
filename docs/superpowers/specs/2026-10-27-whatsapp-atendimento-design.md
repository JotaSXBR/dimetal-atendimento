# Plataforma interna de atendimento WhatsApp — design

Data: 2026-10-27 (revisão 4 — stack de 27/10/2026, estados de setor, configuração no banco e
Coolify)
Status: aprovado em 2026-10-27; implementação começa pela Fase 0
(`docs/superpowers/plans/2026-10-27-fase-0-canal-whatsapp.md`). Configuração do WAHA em
`docs/superpowers/specs/waha-config.md`.

## 1. Por que

Hoje a empresa usa o Cam Krolik (SaaS, assinatura mensal) para atendimento via WhatsApp. O
motivo de trocar não é preço: é a instabilidade da conexão não oficial que o SaaS mantém, que
derruba o atendimento e não dá controle nenhum sobre o que acontece por baixo.

O objetivo é uma plataforma interna, sem venda, com conexão própria via WAHA (engine GOWS) na
VPS da empresa, que faça o que a operação realmente usa — e que a direção consiga auditar de
verdade.

## 2. A operação

- 5 atendentes humanos, todos em navegador desktop (Windows).
- 1 número de WhatsApp hoje, indo para 2 no curto prazo.
- Antes do humano, um chatbot **sem IA** faz o pré-atendimento: menu de opções, captura de
  contexto, e transfere para o setor certo (Comercial, Financeiro, Vendas, Diretoria ou SAC).
- O humano conduz a conversa e fecha a venda.
- Atendimento em horário comercial, configurável, com feriados.
- Uso interno, uma única empresa.

## 3. Objetivos

1. Conexão WhatsApp estável e observável, com recuperação rápida quando cair.
2. Painel único onde os 5 atendentes trabalham, com fila por setor, assumir ticket e transferir.
3. Construtor visual de fluxo de pré-atendimento, no mesmo modelo que a operação já conhece.
4. Atendimento organizado em **tickets** com começo, meio e fim, e tickets anteriores do mesmo
   contato sempre consultáveis.
5. Auditoria para a direção em três frentes: produtividade por atendente, SLA de resposta
   (ninguém fica sem resposta) e histórico das conversas.

## 4. Não-objetivos (MVP)

- App mobile próprio (fica para depois do MVP; os atendentes usam desktop).
- Chatbot com IA. O fluxo é determinístico: menu, opções, mensagens.
- Multi-empresa, multilocatário, revenda.
- Campanhas / disparo em massa, transmissão, listas de transmissão.
- Canais além de WhatsApp (Instagram, Telegram, e-mail).
- Grupos de WhatsApp (o fluxo principal é 1:1 com o cliente).
- Aplicativo nativo, push notification, transcrição de áudio.
- Integração com ERP/CRM.
- Failover automático entre números.

## 5. Anti-objetivos

O projeto fracassa se:

- A conexão cair e ninguém perceber antes do cliente reclamar.
- Mensagem recebida não aparecer no painel ou aparecer duas vezes.
- Algum atendente conseguir apagar ou alterar histórico (a auditoria perde o valor).
- O cliente conseguir ficar preso num fluxo de bot sem saída.
- O disco da VPS encher por causa de anexos antigos.
- O sistema exigir manutenção diária de quem é dev júnior e trabalha nele nas horas vagas.

## 6. Restrições

- VPS 24h já existente, com domínio próprio e HTTPS.
- Um único desenvolvedor (júnior), mantendo o código depois. TypeScript é o background dele;
  Laravel é a escolha declarada para este projeto.
- WhatsApp via API não oficial (WAHA + GOWS). Existe risco de bloqueio do número — inerente à
  escolha, não eliminável por design.
- Volume estimado: ordem de grandeza de 1.000 a 3.000 mensagens/dia.

## 7. Decisões do dono do produto

- Laravel, com o starter kit oficial Livewire (Laravel 13 + Livewire 4 + Flux UI + Tailwind).
- WAHA self-hosted na VPS, engine GOWS, usando a imagem específica do engine (`:gows`), para não
  carregar código dos outros engines.
- Começar do zero em dados: nada é migrado do Cam Krolik. O histórico pode ser importado do
  próprio WhatsApp via WAHA, mas **o backfill é opcional e desligado por padrão**: quem conecta o
  número pela primeira vez escolhe importar ou não e por quantos dias (sugestão de 5; `0`
  desliga). Sem escolha explícita, nada é importado.
- Mobile próprio depois do MVP; MVP é web desktop.
- **Horário de funcionamento** editável, com feriados. Fora do horário, a primeira mensagem do bot
  informa o horário de atendimento e avisa que a resposta vem no próximo horário. A conversa
  **entra na fila do mesmo jeito**.
- **Mensagem enviada pelo celular**, fora do painel, é registrada no histórico marcada como
  "fora do painel" e atribuída ao atendente dono do ticket. Conta como resposta para o SLA e
  aparece sinalizada na auditoria.
- **Um fluxo único** de pré-atendimento vale para os dois números.
- **Modelo de ticket**: mesmo ticket enquanto não encerra; depois de encerrado, mensagem nova abre
  ticket novo vinculado ao mesmo contato, com os anteriores visíveis. Encerramento automático
  após 24h sem movimento.
- **Protocolo** no formato `2026-000123` (ano + sequencial).
- **Retenção**: o texto das mensagens e os eventos ficam para sempre; os **arquivos** (imagem,
  vídeo, PDF, áudio) são apagados do disco após **90 dias**, permanecendo o registro de que
  existiram.
- **Permissões**: atendente vê a fila do seu setor e os próprios tickets; supervisor vê todos os
  setores e pode reatribuir/encerrar; admin vê tudo e é quem exporta auditoria.
- **Setores**: cinco — Comercial, Financeiro, Vendas, Diretoria e SAC, cada um em um de três
  estados:
  - **Ativo** — aparece no menu do bot e recebe tickets.
  - **Fora do menu** — não aparece no menu, mas continua recebendo transferências e mantém fila.
  - **Desativado** — não recebe nada novo e some das listas de escolha; histórico e tickets
    anteriores permanecem. Desativar um setor com ticket aberto é bloqueado.

  Um número de WhatsApp só tem um fluxo, então o menu do bot lista os setores ativos na ordem
  definida pela direção. Um atendente pode pertencer a mais de um setor (é o caso de quem cobre
  Vendas e Comercial), e a fila que ele vê é a soma dos seus setores.

## 8. Decisões do builder (podem ser contestadas)

- **Base de stack (27/10/2026)**: PHP 8.5, Laravel 13.39, Livewire 4.4.8, Flux 2.13, Tailwind 4.3,
  PHPUnit 12.5 (o starter kit oficial não usa Pest), Node 24 LTS, PostgreSQL 18.6, Redis 8.10,
  WAHA 2026.9.2, Coolify 4.5.0.
- **Banco**: PostgreSQL 18. Relatórios e JSON (mensagens, opções de menu) ficam bem servidos, e
  é o que eu sei manter com segurança nesse volume.
- **Configuração no banco**: o máximo possível sai do `.env` e vira tabela `settings` + tela
  (WAHA, empresa, horário, SLA, retenção, backfill, respostas rápidas, SMTP, backup). No `.env`
  ficam só `APP_KEY`, banco, Redis e domínio. Segredos são gravados com cast `encrypted`.
- **Tempo real**: Laravel Reverb (WebSocket self-hosted) + Echo; o Livewire escuta
  `echo-private:` direto, sem SPA.
- **Fila/cache**: Redis, com worker separado. Sem Horizon no MVP (fila pequena; `queue:work`
  supervisionado basta).
- **Construtor de fluxo**: canvas em JavaScript dentro de uma ilha, usando Vue Flow. O resto do
  painel é Livewire puro. Essa é a única parte do produto que não é PHP.
- **Menu type**: só texto no MVP. Os três nós que a operação usa hoje são "Tipo Menu: Texto", e
  botões/listas interativas no WAHA são `sendButtons` depreciado. Suportar botão depois é
  extensão, não requisito.
- **Distribuição**: por setor, com "assumir ticket" (claim). Quem assume primeiro leva; ninguém é
  forçado a receber. A direção vê quem está sem atender.
- **Mídia**: baixada do WAHA no recebimento e guardada no disco da VPS (com backup), servida por
  rota autenticada. Não dependemos do armazenamento do WAHA.
- **Autenticação**: e-mail/senha do starter kit, sem cadastro público. Usuários criados por
  admin. Papéis: `admin`, `supervisor`, `atendente`.

## 9. Modelo de domínio

- `channels` — número de WhatsApp: nome, `waha_session`, telefone, status da sessão
  (`WORKING`/`SCAN_QR_CODE`/`FAILED`...), último evento de conexão, `backfill_days` (padrão 0 =
  desligado; sugestão 5 quando a importação é escolhida na conexão), `backfill_last_run_at`.
- `sectors` — Comercial, Financeiro, Vendas, Diretoria e SAC: `name`, ordem no menu,
  `status` (`ativo | fora_do_menu | desativado`). Setor com tickets abertos não pode ser excluído
  nem desativado.
- `settings` — chave/valor com tipo e cast; segredos cifrados. Alimenta WAHA, empresa, horário,
  SLA, retenção, SMTP e backup.
- `users` — atendente/supervisor/admin, um ou mais setores, ativo/inativo.
- `contacts` — `wa_id` (telefone), nome do WhatsApp, apelido interno.
- `business_hours` — janelas por dia da semana; `holidays` — datas pontuais. Editável por admin.
- `tickets` — contato + canal; `protocolo` (`2026-000123`, único); estado
  (`bot | aguardando | em_atendimento | encerrado`); `assigned_user_id`; `sector_id`;
  `closed_reason` (`manual | inatividade | bot_erro | bot_timeout`); marcos de tempo
  (`opened_at`, `first_human_reply_at`, `last_inbound_at`, `last_outbound_at`, `closed_at`),
  todos os cálculos de SLA considerando apenas horário de funcionamento.
- `messages` — append-only: `wa_message_id` (único), `ticket_id`, direção, tipo, corpo,
  `media_path`/`media_purged_at`, `origin` (`contato | painel | bot | fora_do_painel`), autor,
  `sent_at`, status de entrega/leitura.
- `message_events` / `ticket_events` — append-only, a trilha de auditoria: `ticket_opened`,
  `bot_node_entered`, `bot_option_selected`, `bot_error_exit`, `bot_timeout_exit`, `queued`,
  `claimed`, `transferred`, `closed`, `reopened`, `sla_breached`, `media_purged`. Guarda ator,
  timestamp e payload.
- `flows`, `flow_versions`, `flow_nodes` — ver seção 10.

Toda escrita das tabelas `messages`, `ticket_events` e `message_events` é feita pelo servidor. Não
existe edição nem exclusão pela interface, nem para admin. A única remoção prevista é o arquivo
de mídia após 90 dias, e ela deixa evento registrado.

## 10. Motor de fluxo (o que os prints ensinam)

Um fluxo é um grafo de nós do tipo **Menu de Opção**. Cada nó tem:

- nome;
- uma lista ordenada de mensagens, com variáveis interpoladas (`{{nome}}`, `{{protocolo}}`,
  `{{atendente}}`, `{{horario_atendimento}}`);
- opções numeradas (`1`, `2`, ...), cada uma com rótulo e destino (outro nó, ou ação);
- máximo de tentativas inválidas (`0` = ilimitado, como no print);
- tempo máximo de espera (`0` = sem timeout);
- saídas explícitas: `Erro` (tentativas esgotadas) e `Timeout` (espera esgotada), cada uma com
  destino;
- ação de pós-execução (no print: "Regra de Ação Pós Execução: transferir para Comercial").

Semântica:

1. Ao entrar no nó, envia as mensagens na ordem e zera tentativas/inicia o relógio.
2. Mensagem recebida é normalizada (trim; aceita `1`, `1.`, `1 -`). Se casa com opção, transiciona.
3. Se não casa: conta tentativa inválida e reenvia as mensagens do nó. Ao estourar o máximo, vai
   para a saída `Erro`.
4. Se o tempo de espera expira e o ticket continua no nó, vai para a saída `Timeout`. Isso é um
   job agendado com cancelamento — não um cron varrendo tudo.
5. Ação de transferência põe o ticket em `aguardando` no setor, dispara a distribuição e o bot
   para de responder naquele ticket.
6. Toda transição vira evento. Nó que aponte para si mesmo sem saída, ou ciclo fechado, é
   bloqueado na publicação (validação de grafo).
7. Fora do horário de funcionamento, o nó de entrada anexa o aviso de horário e a promessa de
   resposta no próximo período; a fila continua recebendo.

O construtor visual é o mesmo grafo: nós arrastáveis, arestas por opção, inspetor lateral com os
campos acima, e "publicar versão". Fluxo publicado é imutável; editar cria nova versão, para que
a auditoria de um ticket antigo continue fiel ao fluxo que rodou.

## 11. Integração com WhatsApp (WAHA/GOWS)

- Container `waha` com a imagem do engine GOWS, `WHATSAPP_DEFAULT_ENGINE=GOWS`, API key, e
  webhook apontando para o app. Matriz completa de variáveis em
  `docs/superpowers/specs/waha-config.md`.
- Recebimento: `POST /webhooks/waha` autenticado por **HMAC-SHA512 do corpo bruto**, responde 200
  rápido e enfileira. Eventos tratados: `message`, `message.ack`, `session.status`,
  `message.reaction`.
- Presença: o WAHA mantém "online" por `WAHA_PRESENCE_AUTO_ONLINE_DURATION_SECONDS` (25s) após cada
  chamada, imitando o WhatsApp Web. O envio do painel termina com presença `offline` para o celular
  do atendente voltar a receber push.
- Envio: serviço PHP `WahaClient` chamando `/api/sendText`, `/api/sendImage`, `/api/sendFile`,
  `/api/startTyping`, `/api/sendSeen`. `wa_message_id` devolvido é gravado; falha de envio é
  visível no painel e reenviável.
- Mensagem com `fromMe` que não saiu do painel: grava com `origin = fora_do_painel`, atribui ao
  dono do ticket e registra evento.
- Importação inicial: ao conectar um número, se o backfill estiver ligado e com
  `backfill_days > 0` (padrão 5, editável por número, `0` desliga), um job lê
  `/api/{session}/chats` e, para cada chat,
  `/api/{session}/chats/{chatId}/messages` com filtro de timestamp do período, baixando mídia.
  Deduplicação por `wa_message_id`. Cada conversa importada vira um ticket **encerrado**, só de
  histórico. O backfill pode ser disparado de novo manualmente pelo painel, sem duplicar nada.
- Idempotência: o webhook pode repetir; a unicidade de `wa_message_id` resolve.
- Reconexão: o painel mostra o status da sessão e, se cair, permite reescanear o QR na hora, sem
  mexer no servidor.
- Autenticação do número: a tela oferece **QR e código de pareamento**
  (`POST /api/{session}/auth/request-code`). O código é digitado no celular em "Conectar com número
  de telefone". O QR permanece sempre visível como alternativa, porque o código nem sempre está
  disponível.

## 12. Realtime e concorrência

- Cada ticket tem canal privado; o painel escuta e insere mensagem sem polling.
- "Assumir ticket" é `UPDATE ... WHERE assigned_user_id IS NULL` em transação. Dois cliques
  simultâneos não viram dois donos.
- Presença por setor via `echo-presence` para a fila mostrar quem está online.

## 13. Auditoria, SLA e relatórios

- **Produtividade**: tickets atendidos, mensagens enviadas, tempo médio de primeira resposta,
  tempo médio de resolução, transferências e respostas "fora do painel", por
  atendente/setor/dia.
- **SLA / ninguém abandonado**: ticket em `aguardando` ou `em_atendimento` com última mensagem do
  cliente mais velha que o limite configurado gera alerta vivo no painel e evento
  `sla_breached`. O relógio só corre dentro do horário de funcionamento; ticket que chega às 22h
  começa a contar às 8h do dia seguinte. A direção vê a lista, não um gráfico.
- **Histórico**: busca por contato, protocolo, texto e período; transcrição completa e exportável
  por admin (CSV/PDF), com o fluxo que rodou e os eventos de atendente.
- **Retenção**: texto e eventos permanentes; arquivos apagados após 90 dias por comando agendado,
  com evento `media_purged` e indicação visual no painel de que o anexo expirou. Backup diário de
  banco e mídia, com teste de restauração documentado.

## 14. Deploy

VPS Linux com **Coolify 4.5.0**, dois projetos separados: `main` (produção) e `beta`.
Cada projeto tem `app` (PHP-FPM + Nginx), `queue-worker`, `scheduler`, PostgreSQL 18, Redis 8 e
WAHA/GOWS. Só o `app` recebe domínio HTTPS público; Postgres, Redis e WAHA ficam na rede interna do
Coolify. O beta usa um número de WhatsApp próprio, com sessão própria.

Código no GitHub público (`dimetal-atendimento`, MIT), deploy pelo webhook do Coolify: push em
`main` sobe produção, push em `beta` sobe o beta. A branch `main` é protegida (PR + CI verde +
aprovação). CI: PHPUnit em PostgreSQL 18 e Redis, Larastan nível 6, Pint e Dependabot.

Sessão do WAHA em volume persistente (`/app/.sessions`), então redeploy não pede QR de novo.
Backup diário de banco e mídia em volume local com retenção de 30 dias; destino S3-compatible fica
para depois, atrás da mesma interface.

## 15. Fases

0. **Conexão**: base do app, papéis/setores, configuração no banco, WAHA/GOWS de pé, QR conectado,
   mensagem entra e texto de teste sai. Prova que o problema original está resolvido. O backfill
   entra na Fase 1, junto com contatos, mensagens e tickets.
1. **Inbox**: contatos, tickets, mensagens em tempo real, setores, assumir, transferir, encerrar,
   mídia, horário de funcionamento. Aqui a operação já consegue trabalhar sem o Cam Krolik.
2. **Bot**: motor + editor visual + publicação de versão + eventos.
3. **Auditoria**: relatórios, alertas de SLA, busca e exportação.
4. **Pós-MVP**: app próprio (PWA primeiro, nativo se necessário), grupos, botões interativos,
   notificações.

## 16. Identidade visual

Definida no canvas do pen.dev (documento `untitled.pen`, frames **Design System** e **Inbox**) e
resumida aqui para a implementação:

- **Cor**: tinta `#101513`, texto secundário `#46514D`, apoio `#6F7A75`, linha `#E2E0D9`,
  canvas `#F5F4F0`, superfície `#FFFFFF`. Único acento: verde `#12705A` (escuro `#0B4D3D`, suave
  `#E3F1EB`). Aviso `#9A5B0B`, erro `#B42318`, bot `#4A5A66`. Verde significa ação ou presença;
  nada mais usa verde.
- **Tipografia**: Inter na interface; JetBrains Mono em protocolo, telefone, horários e números
  que a equipe fala em voz alta. Escala 40/28/20/16/14/13/12.
- **Espaçamento**: escala de 4 (4, 8, 12, 16, 24, 32, 48). Raios 6, 10, 14 e pill.
- **Densidade**: compacta, própria de ferramenta de operação. Sem sombras decorativas; hierarquia
  por peso de texto, superfície e linha de 1px.
- **Componentes**: botão primário (verde), secundário (contorno), ghost e destrutivo; campo,
  busca e select; badges de estado com ponto colorido; bolhas de mensagem (entrante branca, saída
  verde-clara, bot cinza-azulado com rótulo "BOT"); linha de ticket com avatar, prévia, protocolo
  e badge; cartão de nó do fluxo com opções, tentativas, timeout e ação; painel de detalhes com
  SLA, tickets anteriores, tags e anexos.
- **Regra de estado**: todo estado aparece por texto além de cor. SLA vencido é uma lista viva no
  painel, não um gráfico.

## 17. Riscos conhecidos

- **Bloqueio do número** por uso de API não oficial. Não há solução técnica; há número reserva e
  histórico preservado localmente.
- **Sessão do WhatsApp cai** quando o celular desvincula ou fica muito tempo offline. Mitigação:
  alerta de sessão, reescaneamento pelo painel.
- **GOWS e botões**: menu interativo pode não funcionar de forma confiável; por isso o MVP é
  texto.
- **Mídia apagada em 90 dias**: se surgir demanda jurídica sobre um anexo antigo, ele não existe
  mais. O texto e o evento permanecem.
- **Cinco setores, cinco atendentes**: setor coberto por uma pessoa só fica desassistido quando
  ela está fora ou de férias. O painel mostra a fila sem ninguém online e supervisor/admin veem
  tudo, mas não existe redistribuição automática — é decisão de operação, não do software.
- **Um dev júnior só**: o design evita peças desnecessárias, mas Laravel + Livewire + canvas JS
  + Reverb + WAHA é bastante superfície. A ordem das fases permite parar em qualquer ponto com
  algo útil rodando.
- **VPS única**: sem alta disponibilidade. Backup e tempo de restauração documentados valem mais
  que redundância aqui.
