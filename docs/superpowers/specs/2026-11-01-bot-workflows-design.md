# Bot de pré-atendimento: workflows com editor visual

Status: rascunho para aprovação do dono (2026-11-01). Substitui a "Fase 2: Bot" do spec
`2026-10-27-whatsapp-atendimento-design.md` (seções 2, 6 e 15), mantendo suas regras: sem IA, determinístico, cliente
nunca preso.

## 1. Intenção

Hoje toda mensagem de cliente novo cai direto na fila (`aguardando`). O dono quer um pré-atendimento: saudação
personalizável, cliente escolhe o setor digitando um número, recebe uma mensagem de encaminhamento (com o número do
ticket) e só então entra na fila do setor. Além desse caminho simples, quer poder montar outros workflows num editor
visual, ao estilo Typebot/Botpress, com vários workflows salvos, versões e ativação/desativação por número.

O motivo: o atendente deixa de receber "oi" sem setor, o cliente já sai com protocolo, e a operação ganha uma base
para automações futuras (inclusive um LLM no lugar do fluxo, ver 11).

## 2. Objetivos, não objetivos, antiobjetivos

Objetivos
- Fluxo simples (saudação → menu de setores → encaminhamento) pronto e editável sem conhecer o editor.
- Editor visual de grafo com os blocos da seção 5, rascunho, versões imutáveis e publicação.
- Um workflow ativo por canal; vários salvos.
- Conversa do bot visível a todos, em leitura, na fila Pendente.

Não objetivos (ficam fora da v1)
- IA/LLM, bloco de webhook/API, botões e listas do WhatsApp (o GOWS não os suporta: o menu é texto numerado).
- Gatilho por palavra-chave ou horário para escolher workflow; mais de um workflow ativo por canal.
- Importar/exportar workflow, funil/analytics do bot, campos customizados novos no contato.
- Reiniciar o menu com ticket já aberto.

Antiobjetivos (falha mesmo que "funcione")
- Cliente preso no bot sem saída.
- Bot e humano respondendo juntos.
- Editar o fluxo ao vivo e quebrar conversas em andamento.

## 3. Decisões do dono (conversa de 01/11)

- Blocos v1: núcleo, espera e mídia, dados no contato e etiquetas (seção 5).
- Um workflow ativo por canal; sem workflow ativo, o canal funciona como hoje (ticket vai a `aguardando`).
- Rascunho + Publicar: versão numerada imutável; conversa em andamento termina na versão em que começou.
- Opção inválida: repete até 2 vezes com aviso; na 3ª encaminha à fila padrão. Silêncio: após N minutos (configurável por
  bloco, padrão 10) encaminha à fila padrão. `#sair` em qualquer ponto também encaminha à fila padrão. Fila padrão = o
  primeiro setor que recebe tickets na ordem do menu (`menu_order`).
- O ticket nasce na primeira mensagem com status **Pendente** (não "bot"), com protocolo. Todos os atendentes veem a fila
  Pendente em modo leitura e podem **transferir** o ticket; não podem assumir. O SLA só começa quando vira `aguardando`.
- O bot só roda em ticket novo (contato sem ticket aberto no canal). Mensagem enviada pelo celular não dispara.
- Fora do horário: o workflow decide (bloco Condição); canal sem workflow mantém a resposta automática atual.
- Admin e supervisor criam, editam e publicam workflows.
- Dados: variáveis da conversa, campos já existentes do contato e etiquetas simples no ticket.

## 4. Abordagens consideradas

Motor igual nas três: grafo em JSON no Postgres, estado por conversa em tabela, job na fila avançando o fluxo.
Canvas: **A. Rete.js** como ilha de JS no Livewire (escolhida pelo dono: MIT, ativo, núcleo sem framework, não traz Vue/React);
B. Drawflow (parado desde 10/2024, nós ricos limitados); C. Vue Flow (exige introduzir Vue). Editor em lista/formulário
descartado: o dono pediu visual. O formato do grafo é nosso e independe da biblioteca; trocar o canvas depois não mexe no motor.

## 5. Blocos da v1

Todo bloco tem `id`, `tipo`, posição e dados. Variáveis em texto usam `{{nome}}`.

| Bloco | O que faz | Saídas |
|---|---|---|
| Início | Entrada única. | 1 |
| Mensagem | Envia texto (saudação, encaminhamento). | 1 |
| Menu de setores | Lista os setores ativos na ordem do menu (`1 - Vendas`), com texto de abertura e de opção inválida. | 1 por setor, calculada na execução; o editor mostra uma saída "Setor escolhido" |
| Menu | Menu numerado com opções escritas à mão; cada opção liga a um caminho. | 1 por opção |
| Pergunta | Envia texto, espera resposta, guarda na variável. Validação: texto, e-mail ou número. | 1 |
| Condição | Testa horário de funcionamento/feriado ou valor de variável. | verdadeiro, falso |
| Esperar | Pausa de N segundos (1 a 30), mostrando "digitando". | 1 |
| Enviar mídia | Imagem, documento ou áudio fixos (arquivos hoje no storage local; migram com o RustFS). | 1 |
| Salvar no contato | Grava variável em campo existente do contato (nome, e-mail, empresa) e aciona a sincronização Google já existente. | 1 |
| Etiquetar | Aplica etiqueta ao ticket. | 1 |
| Transferir para setor | Move o ticket ao setor (ou ao setor escolhido no menu), vira `aguardando`, fecha a sessão do bot. | fim |
| Encerrar | Encerra o ticket com motivo `bot` (sem fila). | fim |

Regras do grafo, validadas ao publicar (`FlowValidator`, no servidor): um único Início; todo nó alcançável; toda saída
ligada; todo caminho termina em Transferir ou Encerrar; todo ciclo contém um bloco que espera o cliente (Menu, Menu de
setores ou Pergunta); setores e etiquetas referenciados existem. Erro de validação impede publicar e aponta o bloco.

## 6. Motor

Sessão: tabela `bot_sessions` (uma por ticket): ticket, versão do workflow, nó atual, variáveis (jsonb), tentativas no nó,
`expires_at`, estado (`ativa`, `transferida`, `encerrada`, `abandonada`).

Fluxo de uma mensagem do cliente:
1. `IngestMessage` grava a mensagem. Se o ticket é novo e o canal tem workflow ativo, abre o ticket `pendente` e cria a
   sessão na versão publicada; se há sessão ativa, entrega a mensagem a ela; se não, comportamento atual.
2. Um job `AdvanceBotSession` (fila `inbound`, um por ticket sob lock, como a abertura de ticket de hoje) executa os nós
   até um bloco que espera o cliente, um nó final ou o teto de 50 passos (proteção contra laço).
3. Mensagens do bot saem por `SendTicketMessage` com `MessageOrigin::BOT`. Hoje esse caminho **não** finge visto nem
   "digitando" (decisão da resposta automática), e o bloco Esperar é o único que mostra "digitando" de propósito. O eco do
   WhatsApp continua casando pela janela de bot.
4. `#sair` (sem diferenciar maiúsculas) em qualquer ponto: transfere à fila padrão com a mensagem de encaminhamento padrão.
5. Rotina de minuto (scheduler, `withoutOverlapping`) encaminha as sessões com `expires_at` vencido à fila padrão.
6. Mensagem enviada pelo celular numa conversa `pendente` encerra a sessão e o ticket segue o caminho de hoje
   (`em_atendimento`); o bot nunca fala depois de um humano.
7. Mensagem recebida depois que a sessão terminou segue o ticket normal.

Eventos do ticket (`TicketEvent`): `bot_started`, `bot_handoff` (motivo: `fluxo`, `sair`, `erros`, `timeout`),
`bot_closed`. Variáveis do sistema: `{{nome}}`, `{{protocolo}}`, `{{setor}}`, `{{horario}}`, `{{proximo_horario}}`.

## 7. Dados

- `workflows`: nome, rascunho (jsonb), `updated_by`, `deleted_at`.
- `workflow_versions`: workflow, número sequencial, grafo (jsonb imutável), `published_by`, `published_at`.
- `channels.workflow_id` (nulo = sem bot): "ativar" é escolher o workflow do canal; vale a última versão publicada.
- `bot_sessions` (seção 6).
- `tickets.status` ganha `pendente`; `closed_reason` ganha `bot`.
- `tags` (nome único, cor) e `ticket_tag`.
- Voltar a uma versão antiga = copiá-la para o rascunho e publicar (gera número novo; versões nunca mudam).
- Workflow com canal ativo não pode ser apagado; versões ficam enquanto o workflow existir.
- Seed: workflow "Atendimento padrão" (saudação → menu de setores → encaminhamento com protocolo), **inativo** até o admin ativar.

## 8. Fila Pendente e inbox

Regra que guia todo ponto do código: **`pendente` é a triagem automática, sem humano (workflow hoje, LLM depois);
`aguardando` é a fila humana do setor.** Sem workflow ativo no canal o ticket nasce `aguardando`, como hoje.

- `Ticket::open()` e as listas passam a conhecer `pendente`: aba/seção Pendentes em Inbox, visível a todos; o transcript abre
  em leitura (sem caixa de resposta, sem assumir). Ação disponível: Transferir (reusa `TransferTicket`, que já devolve a
  `aguardando` no setor de destino).
- `waiting_since`/SLA e alerta de fila (`AlertDispatcher::queued`) só disparam ao virar `aguardando`.
- Sessão e ticket pendente que ninguém transferiu se resolvem pelo timeout; nunca ficam órfãos.

## 9. Interface

- Menu Automação (admin e supervisor): lista de workflows com estado, canal vinculado, versões e quem publicou;
  Novo, Duplicar, Apagar, Ativar/Desativar por canal, Histórico de versões com "Restaurar no rascunho".
- Editor: canvas Rete.js numa ilha de JS que lê/grava o grafo no Livewire; paleta de blocos; painel lateral (Alpine) edita
  os dados do bloco selecionado; botões Salvar rascunho, Validar, Publicar. O JSON é a única ponte entre canvas e servidor.
- Telas e componentes usam Flux e os tokens atuais. O dono refaz o visual depois (regra da memória); aqui só funcional.
- Etiquetas: cadastro simples dentro de Automação; chip no ticket e filtro na lista.

## 10. Segurança e limites

- Autorização por policy (admin/supervisor); o grafo é dado não confiável: o motor ignora tipos/campos desconhecidos e
  nunca executa código. Texto do cliente nunca é interpolado como template.
- Teto de 50 passos por execução; mensagens do bot entram no limite de envio existente.
- CSP continua em `report-only`; a biblioteca do editor é empacotada pelo Vite (sem CDN).
- Mídia do bot passa pelo mesmo `AttachmentRules` do envio.

## 11. Futuro: LLM no lugar do workflow

Previsto, não implementado: o ponto de entrada do bot (`BotEntry`) recebe a mensagem e decide quem conduz. Hoje só há o
condutor "workflow". Um condutor "LLM" entra depois por canal, sem tocar o ticket pendente, a transferência nem a
sessão. Não criar coluna nem interface genérica agora (YAGNI).

## 12. Testes

TDD por tarefa. Cobrir: validador (cada regra); motor (menu válido/inválido/3 erros, `#sair`, timeout, Pergunta com
validação, Condição de horário, Esperar, teto de passos, versão fixa em conversa em andamento); `IngestMessage` (ticket
novo com/sem workflow, ticket aberto, `fromMe`); permissões; fila Pendente (leitura, transferir, sem assumir, SLA só depois);
publicação/versões/restauração; um teste de feature do fluxo simples ponta a ponta com `Http::fake` do WAHA.

## 13. Escolhas minhas, para o dono conferir

1. Esperar 1 a 30 s e teto de 50 passos.
2. Mensagem do celular numa conversa pendente encerra o bot e segue o fluxo atual (`em_atendimento`).
3. Seed inativo do "Atendimento padrão".
4. Etiquetas: só nome e cor; filtro na lista, sem relatório.
5. "Salvar no contato" só grava campos que já existem no `Contact`; o construtor confere quais.
6. Mídia do bot usa o storage local até o RustFS.
7. Pergunta valida só texto/e-mail/número (sem CPF).

## 14. Fatias sugeridas (para o plano)

1. Dados + status `pendente` + fila Pendente (leitura/transferir).
2. Grafo, validador e motor (sem editor), com o fluxo padrão por seed.
3. Integração ao `IngestMessage`, timeout, `#sair`, eventos.
4. Telas Automação (lista, versões, ativação) e etiquetas.
5. Editor Rete.js.
