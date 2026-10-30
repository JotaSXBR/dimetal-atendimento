# Atendimento completo — design

Data: 2026-10-30. Aprovado pelo dono na conversa de 30/10 (escopo e assinatura); as decisões abaixo marcadas "(builder)" foram
delegadas a quem implementa. Referência visual: arquivo Pencil `Dimetal.pen` (telas "Config - Respostas rapidas", "Modal -
Encerrar ticket", "Contato detalhe", "Config - Empresa"). O bot fica por último (decisão do dono).

## 1. Por que

O atendente hoje responde e encerra com um botão. Faltam: textos prontos, saber por que um ticket fechou, ver o histórico do
cliente e assinar as mensagens com o nome de quem atende.

## 2. Itens (cada um com testes)

**Menu.** "Fila do setor" passa a se chamar **"Atendimento"** (só o rótulo; a rota continua `inbox`).

**Respostas rápidas.**
- Tabela `quick_replies` (`shortcut` único, `body`, `sector_id` anulável = todos os setores).
- Configuração em `configuracoes/respostas-rapidas` (supervisor e admin): criar, editar, apagar.
- Na barra de mensagem, o rascunho que começa com `/` (sem espaço) lista até 6 respostas cujo atalho começa igual, só dos
  setores do usuário e as de todos os setores; clicar coloca o texto no campo, editável. (builder) Sem navegação por teclado e
  sem contador de usos na v1.
- Atalho: `[a-z0-9_-]{1,30}`; texto até 1000 caracteres.

**Encerrar ticket com motivo e resumo.**
- O botão Encerrar abre um painel: **motivo** obrigatório (`resolvido`, `sem_resposta`, `cliente_pediu`) e **resumo** obrigatório
  (3 a 500 caracteres), como no desenho (builder: segui o desenho; o resumo alimenta o histórico).
- Colunas novas em `tickets`: `close_outcome`, `close_summary`. O evento `closed` guarda os dois no payload.
- Encerramento automático por inatividade e fusão continuam sem motivo/resumo.
- Fora da v1: tags, "abrir ticket vinculado", "pausar 24 h", tela própria de ticket encerrado.

**Histórico na ficha do contato.** A ficha (inbox e tela Contatos) lista os últimos 10 tickets do contato: protocolo, abertura,
setor, estado e, se encerrado, motivo e resumo.

**Assinatura das mensagens** (como o Chatwoot).
- Modelo em *Configurações → Empresa*, com `{{atendente}}`, `{{setor}}` e `{{empresa}}`. Exemplo do dono:
  `*Jessé @ Dimetal Distribuidora:*` numa linha, linha em branco e a mensagem.
- Formatação do WhatsApp: **negrito** `*x*`, *itálico* `_x_`, ~~tachado~~ `~x~` e `monoespaçado`. O WhatsApp **não tem sublinhado**;
  a tela avisa. Botões B / I / S / mono envolvem o trecho selecionado. Prévia com o nome de quem edita.
- Posição: início (padrão, estilo Chatwoot) ou fim. Liga/desliga geral (admin) e liga/desliga de cada atendente na barra de
  mensagem (coluna `users.sign_messages`, padrão ligado). Só vale quando os dois estão ligados.
- Vale para texto do painel. Não vale para resposta do bot, legenda de arquivo nem reenvio (o reenvio usa o texto já gravado).
- O corpo gravado já inclui a assinatura: o eco `fromMe` do WhatsApp casa pelo texto.
- As bolhas do inbox passam a mostrar `*negrito*`, `_itálico_`, `~tachado~` e `monoespaçado` formatados (HTML escapado antes).

## 3. Dados da empresa (Configurações → Empresa)

Nome fantasia (usado em `{{empresa}}`), modelo e posição da assinatura, liga/desliga, e o **SLA de primeira resposta** em minutos
(padrão 10; usado pelos relatórios). Guardados em `settings` (chave/valor existente). Só admin.

## 4. Não-objetivos

Tags, notas internas, descadastro, contador de usos, canvas de fluxos, sublinhado, exportação CSV.

## 5. Abordagens

Assinatura gravada no corpo (escolhida) versus anexada só na hora do envio: a segunda quebra o casamento do eco e deixa o painel
mostrando um texto diferente do que o cliente recebeu. Respostas rápidas em tabela própria (escolhida) versus em `settings`: a
tabela permite setor e atalho único com índice.
