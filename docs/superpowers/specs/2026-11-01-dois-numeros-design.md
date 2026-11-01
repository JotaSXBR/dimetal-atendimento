# Número do canal, dois números e voltar ao ticket aberto

Data: 01/11/2026. Fatia 2 de 2 da tela de Canais (a fatia 1 é `2026-11-01-canais-design.md`).

## Intenção

O dono vai atender por dois números de WhatsApp com a mesma equipe, e o mesmo cliente (um só contato, no Google Contatos) pode escrever pelos dois. Hoje: (a) o cartão do canal diz "número ainda não lido" mesmo conectado, porque o app nunca grava o telefone; (b) o atendente não vê por qual número a conversa acontece nem que o cliente tem outro ticket no outro número; (c) ao abrir um ticket encerrado, o ticket que estava aberto some da tela e não há caminho de volta.

## Ponto de partida

O padrão do mercado é contato único e um ticket por número, sem vínculo entre os números, e é o modelo que o app já tem. O aviso de "mesmo contato em outro número" não é padrão: fica por nossa conta. Atendentes se confundem sobre qual número usam, então o número aparece de forma explícita.

## Objetivos

1. **Número do canal.** O telefone vem do WAHA (`me.id` em `GET /api/sessions/{sessão}` e no webhook `session.status`) e fica gravado no canal. O cartão mostra `+55 16 99999-0001`.
2. **Troca de número.** Se o WAHA informar um número diferente do gravado: atualiza, guarda o número anterior e a data, mostra aviso no cartão do canal e manda um alerta no sino para todos.
3. **Número no Inbox.** Selo com nome do canal e número no item da lista e no cabeçalho do ticket, **só quando há 2 ou mais canais ativos**.
4. **Faixa "Outros tickets deste contato".** No cabeçalho de qualquer ticket: os outros tickets do mesmo contato, abertos primeiro e depois os dos últimos 30 dias, em qualquer número. Link só para quem pode ver o ticket; quem não pode vê o aviso sem link e sem setor, atendente ou conteúdo. Ticket aberto do mesmo contato em outro número ganha destaque ("também tem ticket aberto no número X").
5. **Voltar ao ticket anterior.** Ao selecionar outro ticket, o app lembra o anterior; um botão "Voltar ao ticket anterior (PROTOCOLO)" o reabre. Cobre o caso de abrir o encerrado de outro cliente.

## Não objetivos

- Responder por outro número ou transferir o ticket de número (a resposta sai sempre pelo número do ticket).
- Impedir que um canal "Desativado" volte a conectar após reiniciar o WAHA (risco registrado na fatia 1).
- Unir os tickets dos dois números num só.

## Antiobjetivos

- Vazar que existe conversa em outro setor (sem link, sem setor, sem atendente, sem conteúdo).
- Pesar o Inbox: a faixa não pode ser recalculada a cada tecla digitada (mesma lição do `Transcript`).
- Responder pelo número errado sem perceber depois de uma troca de chip.

## Decisões do dono

- Troca de número: **atualiza e avisa** (cartão + sino), não bloqueia o envio.
- Selo de número: lista e cabeçalho, só com 2+ canais ativos.
- Aviso de outro número: abertos + últimos 30 dias; link só com acesso.
- Voltar: **faixa do contato + botão Voltar**, porque o encerrado aberto às vezes é do mesmo cliente e às vezes de outro.

## Abordagens consideradas

| Parte | Escolhida | Descartada |
|---|---|---|
| Faixa de outros tickets | Componente Livewire filho `Inbox\ContactTickets` (prop `ticketId`), que só renderiza com `ticket.updated` | Calcular dentro do `render()` do `Inbox` (recalcula a cada tecla) |
| Número gravado | Só dígitos (`5516999990001`), formatado na exibição | Guardar já formatado (formato varia, difícil de comparar) |
| Troca de número | Colunas `previous_phone` e `phone_changed_at` no canal, mais o alerta | Só o alerta (o cartão não saberia dizer depois de lido) |
| Voltar | Propriedade `previousTicketId` no `Inbox`, validada com `readableBy` ao usar | Histórico de navegação no navegador (não sobrevive ao Livewire) |

## Como se encaixa no código

- `Channel.phone` já existe (`$fillable`), mas nada o grava. Migração nova: `previous_phone` (string, nula) e `phone_changed_at` (timestamp, nulo).
- Um único ponto lê o número: `ChannelPhone::sync(Channel $channel, ?string $meId): void`, usado pelo `ChannelCard::refreshStatus` e por `HandleWahaEvent::applyStatus`. Normaliza `me.id` (`5516…@c.us`, ou com sufixo de dispositivo `:12` e `@s.whatsapp.net`) para dígitos; ignora valor vazio (não apaga o número gravado); na primeira gravação não alerta; numa troca real atualiza, guarda o anterior e chama o `AlertDispatcher`.
- Alerta novo `ChannelNumberChanged` (mesmo padrão de `ChannelDown`/`ChannelUp`, `kind = connection`, estado `number_changed`, ids apenas) e a linha no `Bell`.
- `PhoneFormat` ganha a exibição `+55 16 99999-0001` (a partir dos dígitos).
- Selo: `Channel::query()->where('active', true)->count() >= 2` calculado uma vez por render da lista; o `Inbox` já carrega `channel` no ticket selecionado, e a lista passa a carregar `channel`.
- `ContactTickets` usa `Ticket::readableBy()` para o link e uma consulta separada só para o aviso sem link, devolvendo apenas protocolo, número e estado (nunca setor, atendente ou texto). Limite de 5 itens, ordenado: abertos, depois encerrados por `closed_at` desc dentro de 30 dias.
- `Inbox::select` guarda `previousTicketId` (só se o anterior existe, é diferente e ainda é legível); `goBack()` valida `readableBy` e limpa.

## O que fica para quem constrói

- Textos exatos do aviso, do alerta e do botão, no tom do app.
- Posição visual da faixa e do botão no cabeçalho (seguir o Pencil do Inbox; sem desenho novo).
- Forma exata do `me.id` do GOWS (testar `@c.us`, sufixo `:12` e `@s.whatsapp.net`).

## Verificação combinada

- Testes de feature: sincronizar número (primeira gravação sem alerta, igual não faz nada, diferente atualiza e alerta, vazio não apaga, três formatos de `me.id`), webhook e poll usam o mesmo caminho; selo só com 2+ canais ativos; faixa (abertos antes dos encerrados, janela de 30 dias, limite de 5, link só com acesso, sem vazamento de setor/atendente/texto, não recalcula ao digitar); botão Voltar (volta, some depois de usar, ticket que perdeu acesso não volta).
- Conferência no Chrome contra um WAHA falso (cartão com o número, selo, faixa, voltar).
- Depende do dono no beta: ler o número real do aparelho conectado.

## Resultado

Entregue: número do canal vindo do WAHA (poll e webhook pelo mesmo ponto, `ChannelPhone::sync`), aviso de troca de número (cartão por 7 dias e sino, sem som, alerta único mesmo com poll e webhook ao mesmo tempo), selo do número na lista, no cabeçalho e no painel de detalhes com 2 ou mais canais ativos, faixa "Outros tickets deste contato" e botão "Voltar ao ticket anterior". O Desativar passou a desvincular o aparelho (`POST /api/{sessão}/logout`, a rota atual do WAHA) e o Reativar inicia a sessão para mostrar o QR.

Diferenças em relação ao combinado:
- O número aparece como `(16) 99999-0001` (o formato do resto do app), não `+55 16 99999-0001`.
- A faixa aparece também com um só canal: é o caminho de volta ao ticket aberto do mesmo cliente. Só o número é omitido quando o outro ticket é do mesmo canal.
- O cabeçalho do ticket aberto já era apertado (os botões de ação empurram o texto); por isso o número também está no painel de detalhes.

Conferido: testes, Pint, Larastan, PHPMD e a tela no Chrome contra um WAHA falso (número no cartão, selo, faixa e Voltar).

Sem prova ainda (depende do beta): o formato real de `me.id` do GOWS (se vier `@lid`, o cartão continua em "número ainda não lido"); o Desativar com um aparelho real (desvincula o celular); canais desativados antes desta versão só foram parados, não desvinculados.

Em aberto: a faixa e o painel "Atendimentos anteriores" mostram tickets parecidos (decidir depois se unem); a faixa consulta a cada `ticket.updated` de qualquer ticket.

