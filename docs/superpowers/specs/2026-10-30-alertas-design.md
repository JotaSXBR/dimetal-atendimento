# Central de alertas: conexão, SLA e fila, com som e aviso visual (design)

Data: 30/10/2026. Estado: aprovado pelo dono em 30/10/2026 e implementado. Branch (a criar): `feat/alertas`.

## Intenção

Quem atende precisa **perceber na hora** o que exige ação: o WhatsApp que caiu (para todos), o cliente que passou do SLA, o
ticket novo na fila, a mensagem num ticket seu. Hoje o app só mostra isso a quem recarrega a tela, e não tem som. Esta fatia cria
o espaço único dos alertas, começando pelo da conexão do WhatsApp, e deixa lugar para os próximos que ainda não pensamos.
Alerta externo (e-mail, mensagem) fica para o futuro; o mobile também.

## Objetivos

1. WhatsApp caído: faixa visível em toda tela, para todos, na hora, com som repetido até voltar ou o usuário silenciar.
2. Som e aviso por usuário: ligar ou desligar cada tipo, volume, repetir ou não.
3. Sino no menu com a lista do que aconteceu, guardada 7 dias.
4. Balão do navegador (sem texto de cliente) só para os alertas graves.
5. Uma regra simples e testável de quem recebe o quê.

## Não objetivos

E-mail e mensagem externa; mobile; tons escolhíveis; regras por setor; push com o app fechado; alertas de rotinas internas além da
conexão do WhatsApp, do SLA e da fila.

## Anti-objetivos (seria fracasso mesmo funcionando)

Som que o usuário não consegue silenciar e vira atrito com a equipe; alerta que repete sem parar; balão com texto de cliente;
som tocando em duas abas ao mesmo tempo; som antes de o navegador permitir.

## Decisões do dono

1. WhatsApp caído é alerta **crítico**: na hora, para todos, dentro do app, com som. O som pode ser **silenciado por tempo**
   (15 min ou 1 h) pelo próprio usuário; a faixa visual não some até o canal voltar.
2. Eventos com som, cada um ligável por usuário: mensagem em ticket meu, ticket novo na minha fila, ticket atribuído ou
   transferido a mim, SLA perto de estourar ou estourado.
3. Com a aba em foco, não toca para a conversa que já está aberta na tela; toca para os demais tickets.
4. Repetição só para SLA estourado e WhatsApp caído, e **cada usuário pode desligar a repetição**.
5. Balão do navegador entra agora só para os graves (WhatsApp caído e SLA estourado). **Os alertas de SLA podem ser desligados**
   pelo usuário (som e balão) para não causar atrito.
6. Faixa fixa no topo para os graves, mais um sino no menu com a lista dos comuns.
7. O sino guarda o histórico **no banco, por 7 dias**.
8. Três sons fixos (comum, SLA, crítico) com volume por usuário.
9. Abordagem: notificações do Laravel (banco e broadcast) mais faixa de estado; módulo JS único para som e balão.

## Abordagens consideradas

1. **Notificações do Laravel + faixa de estado (escolhida).** Banco e broadcast no canal `users.{id}` que já existe; a conexão é
   estado lido do canal, não notificação; um módulo JS cuida de som, balão e aba líder.
2. Tabela própria de alertas, com início e fim: mais controle, mas reescreve lido e não lido e expurgo.
3. Só tempo real, sem banco: descartada, pois o dono quer histórico.

## Design

### Níveis
| Nível | Exemplos | Faixa | Som | Balão | Repete |
|---|---|---|---|---|---|
| Crítico | WhatsApp caído; sem conexão com o servidor (navegador) | sim, até resolver | sim (silenciável por tempo) | sim | sim, a cada 60 s |
| Grave | SLA estourado | sim (ticket em destaque) | sim (desligável) | sim (desligável) | sim, a cada 2 min até responder |
| Comum | mensagem em ticket meu, ticket novo na fila, atribuído a mim, SLA perto de estourar | não | sim (cada um ligável) | não | não |
| Informativo | WhatsApp voltou | confirmação por 10 s | não | não | não |

### Quem recebe (regra do servidor)
- **WhatsApp caiu ou voltou:** todos os usuários ativos, via o canal privado `inbox`, que já existe.
- **Mensagem em ticket meu:** só o responsável (canal `users.{id}`).
- **Ticket novo na fila:** usuários ativos do setor do ticket; sem setor, todos os que veem a fila geral.
- **Atribuído ou transferido a mim:** o novo responsável.
- **SLA perto de estourar ou estourado:** o responsável (ou, sem responsável, quem vê a fila do ticket) e supervisores e admins.

### Estado da conexão (faixa)
Novo evento `ChannelStatusChanged` no canal `inbox`, só com ids. A faixa lê o estado do canal no banco (a rotina que já
confere `WORKING`), então quem abre a tela depois também a vê. Voltando a `WORKING`, a faixa vira confirmação por 10 s, sem som.
Erro de conexão do **navegador** com o nosso servidor é um segundo alerta crítico, feito no cliente a partir do estado do Echo
(`unavailable`, `disconnected`, `failed`); ao reconectar, a tela recarrega o estado, porque o Reverb não reenvia o que perdeu.

### Notificações guardadas (sino)
Classes de notificação com canais `database` e `broadcast`: atribuído ou transferido, SLA perto de estourar, SLA estourado e
WhatsApp caiu e voltou. **Minha decisão:** mensagem em ticket meu e ticket novo na fila **não** são guardados (o volume lotaria o
sino e já aparecem na lista do Inbox); só tocam som e dão destaque na lista. Expurgo diário das notificações com mais de 7 dias
(comando agendado). Marcar como lido, e "marcar todas".

### Preferências por usuário
Campo `alert_settings` (JSON) no usuário, validado por uma classe de preferências com padrões:
`sound.message`, `sound.queue`, `sound.assigned`, `sound.sla` (liga ou desliga cada um), `volume` (0 a 100), `repeat` (liga ou
desliga), `desktop_notifications` (liga ou desliga), `mute_critical_until` (instante). Padrão: tudo ligado, volume 70, exceto o
balão, que só liga depois de o usuário permitir no navegador. Tela no Perfil, com botão de prévia dos três sons.

### Som e balão no navegador
Um módulo JS único (Alpine) recebe o evento com o tipo do alerta (`kind`: `message`, `queue`, `assigned`, `sla_warn`,
`sla_breached`, `connection`). O servidor entrega as preferências na página; o cliente decide tocar ou não.
- **Gesto do usuário:** o navegador só libera áudio depois de um clique. Uma faixa discreta "Ativar som" aparece enquanto o áudio
  está bloqueado; o mesmo vale para pedir permissão de balão, sempre num clique.
- **Uma aba toca:** aba líder escolhida com Web Locks (com plano B por mensagem entre abas); só ela toca e mostra o balão.
- **Conversa aberta:** não toca para a mensagem do ticket que está aberto na tela e em foco.
- **Acessibilidade:** faixa crítica com `role="alert"`; comuns com `role="status"`; a região existe na página antes de mudar
  o texto; todo som tem equivalente em texto e dá para silenciar (WCAG 1.4.2); balão sem texto de cliente.
- **Sons:** três arquivos livres de direitos (comum, SLA, crítico), curtos.

### Mudanças que o funcionamento pede
`MessageCreated` passa a levar `direction` no payload (nunca o texto); novos `ChannelStatusChanged` e as classes de notificação;
`ChannelObserver` e a rotina de SLA passam a disparar alertas; migração do campo `alert_settings` e comando de expurgo.

### Telas (desenho no Pencil, antes do código)
Faixa de alerta (crítico, grave, confirmação), sino com lista e contador no menu, configurações de alertas no Perfil, faixa
"Ativar som". Mobile fica de fora.

## O que fica para o construtor
Escolha dos três sons; textos exatos das faixas; intervalo exato das repetições (60 s e 2 min são propostas minhas); limite de
quantas repetições antes de parar sozinho.

## Riscos e limites
O som só toca com o app aberto em alguma aba; sem aba aberta, nada avisa (por isso o e-mail e a mensagem externa ficam como
próxima fatia). Safari pode bloquear som sem gesto. O Reverb não reenvia eventos perdidos: a faixa depende do estado no banco
para ser correta depois de uma queda.

## Testes
Quem recebe cada alerta (por papel e setor); preferências com padrão e validação; `mute_critical_until` respeitado; payloads sem
texto de cliente; faixa visível para todos com canal caído e some ao voltar; expurgo de 7 dias; notificação não duplica no mesmo
ticket e evento. O módulo JS fica fino: decide só com o `kind` e as preferências entregues pelo servidor.
