# Histórico de tickets encerrados

Data: 2026-10-30. Branch: `feat/atendimento-completo`. Origem: pedido do dono ("falta uma área para ver e pesquisar os
tickets encerrados"); a spec original (seção 13) já prometia histórico com busca e transcrição, e a fatia 1 e o atendimento
completo adiaram. Desenho Pencil: frame "Ticket encerrado" em `Dimetal.pen` (o construtor deve segui-lo).

## Intenção

Quem atende precisa reencontrar o que foi combinado com um cliente que voltou, e a direção precisa conferir atendimentos.
Hoje o ticket encerrado só aparece nos 10 últimos da ficha do contato, sem busca nem conversa inteira.

## Objetivos

1. **Tela `/historico`**: busca e filtros sobre os tickets encerrados; ao escolher um, a transcrição fica ao lado, só para leitura.
2. **Aba "Encerrados" no Inbox**: os encerrados dos últimos 7 dias, abrindo no painel de conversa já existente, só leitura,
   com link "Busca completa" para `/historico`.
## Fora do escopo

Exportação CSV/PDF (prevista na spec original, não pedida agora; o dono mandou tirar), reabrir ticket, tags, edição ou apagamento de qualquer coisa, busca em
tickets abertos (o Inbox continua com a fila), relatórios novos.

## Anti-objetivos (falha mesmo que "funcione")

- Atendente ver conversa de setor que não é dele, pela lista, pela URL ou pela rota de mídia.
- Filtro de data usar meia-noite UTC (o erro do PR #40): o dia é o de Brasília.
- A busca devolver ticket duplicado quando várias mensagens casam.
- N+1 na lista (contato, setor, atendente, prévia).

## Quem vê o quê (proposta minha, apresentada no chat; o dono escolheu o formato A com B e não a contestou, mas não a confirmou)

Escopo novo `Ticket::scopeInHistoryOf(User)`: só tickets `encerrado`, e
- usuário inativo: nada;
- supervisor e admin: todos;
- atendente: os que ele atendeu (`assigned_user_id`) ou dos setores dele. Ticket sem setor e sem ele como responsável: só
  supervisor e admin.

`TicketPolicy::view` passa a aceitar `visibleTo` **ou** `inHistoryOf`. Isso cobre a seleção no Inbox, a transcrição e a rota
de mídia (`MessageMediaController` já autoriza por `view`). As ações (assumir, responder, encerrar, transferir) continuam
negadas a ticket encerrado pelas policies atuais; nada muda nelas.

## Aba "Encerrados" no Inbox

- Quarta aba (`tab=encerrados`): `inHistoryOf`, `closed_at` nos últimos 7 dias, mais recente primeiro, até 50.
- Cada linha: nome do contato, protocolo, quando encerrou, motivo. Clicar usa `select()` existente (a policy `view` já deixa).
- Painel de conversa em modo leitura: sem compositor, sem Reenviar; cabeçalho mostra motivo e resumo do encerramento.
- Link "Busca completa" leva a `/historico`.

## Tela `/historico`

- Rota `historico`, `auth` + `verified` (como `inbox` e `contatos`); item de menu para todos os papéis.
- Filtros: busca livre (nome ou telefone do contato, protocolo, **texto das mensagens**), período de encerramento (de/até,
  padrão últimos 30 dias, **dia de Brasília**), setor, atendente (só supervisor/admin), motivo do encerramento.
- Lista paginada (25), ordenada por `closed_at` desc. Linha: contato, protocolo, setor, atendente, encerrado em, motivo.
- Selecionar um ticket mostra a transcrição à direita: mensagens (mesma aparência do Inbox), eventos do ticket
  (assumiu, transferiu, encerrou com motivo e resumo) em ordem cronológica, e anexo expirado indicado como no Inbox.
- Busca por texto: `exists (select 1 from messages where ticket_id = tickets.id and body ilike %termo%)`, com `%`, `_` e `\`
  do termo escapados. `exists` evita duplicar ticket. Termo com menos de 3 caracteres não busca no texto (só nos outros campos).
- Migração: `create extension if not exists pg_trgm` e índice GIN `messages(body gin_trgm_ops)`. `pg_trgm` é extensão
  confiável no PostgreSQL 13+, então o dono do banco a cria sem ser superusuário (o beta usa Postgres 18). O índice fica
  porque o volume será alto (2 números na produção): medido em 2,1 milhões de mensagens, a busca por nome caiu de 0,9 s para
  39 ms e a consulta de 30 dias de 0,98 s para 27 ms, ao custo de ~0,7x a tabela em espaço e ~0,5 ms por mensagem gravada
  (`docs/superpowers/notes/2026-10-30-busca-de-texto-benchmark.md`). O filtro de período sozinho não limita a varredura.

## Transcrição compartilhada

Extrair o laço de mensagens de `inbox.blade.php` para um parcial `livewire.inbox.transcript` com `readonly`. O Inbox o usa com
`readonly=false` (comportamento atual, inclusive Reenviar); o Histórico com `readonly=true`. Os eventos têm rótulos em
`Audit\Index::TYPES`: mover o mapa e `detail()` para um lugar comum em vez de duplicar.

## Abordagens consideradas

- **A. Tela própria** `/historico` (leve, sem compositor, comporta exportação). **B. Aba dentro do Inbox** (reaproveita a tela,
  mas o Inbox é o componente mais pesado). **C. Só a ficha do contato** (não busca). **Escolhido pelo dono: A com B**, com a aba
  limitada aos últimos 7 dias e a transcrição compartilhada para não haver duas implementações.
- Busca de texto: `ILIKE` com índice de trigramas, em vez do texto completo do Postgres (radicais de palavra rendem pouco para
  nomes, números e protocolos), e `ILIKE` sem índice (cresce em linha reta com a tabela, ~1 s com 2 milhões de linhas).

## Testes (TDD, RED primeiro)

Escopo e policy por papel (atendente vê setor dele e o que atendeu, não vê outro; inativo nada; sem setor só supervisor/admin;
mídia de ticket fora do escopo dá 403). Busca: texto, protocolo, contato, termo curto, escape de `%`/`_`, sem duplicar. Período
na fronteira de Brasília (23h de Brasília cai no dia certo). Aba dos 7 dias (6 dias entra, 8 não). Modo leitura (sem
compositor nem Reenviar). Teste que falha se a lista
fizer consulta por linha. Migração: o índice `pg_trgm` existe.

## Chamadas do construtor (minhas, para o dono conferir)

1. Regra de visibilidade acima (setor + o que atendeu); sem setor só supervisor/admin.
2. Janela de 7 dias na aba, até 50 linhas.
3. Padrão de 30 dias na tela `/historico`; termo mínimo de 3 caracteres para busca no texto.
4. Sem exportação (o dono mandou tirar).
5. Aba e tela para todos os papéis, atendente restrito pelo escopo; filtro de atendente só para supervisor/admin.

## Fica para o construtor

Posição e aparência seguem o frame "Ticket encerrado" do Pencil; nomes de classes e do parcial; paginação da transcrição
(o Inbox carrega as últimas 200 mensagens; manter o mesmo limite e avisar quando passar).
