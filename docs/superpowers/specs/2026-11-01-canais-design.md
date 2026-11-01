# Tela de Canais: layout do Pencil, excluir/desativar e QR com logo

Data: 01/11/2026. Fatia 1 de 2 (a fatia 2, "dois números", está no fim deste documento só como registro das decisões).

## Intenção

O dono vai ter dois números de WhatsApp (dois canais) atendidos pela mesma equipe. Hoje a tela de Canais só cria, conecta e desconecta, empilha tudo numa coluna e mostra o QR genérico do WAHA. Ele quer: o layout desenhado no Pencil (frame "Config - Canais"), poder **excluir a sessão no WAHA**, poder **desativar** um canal de propósito (para não receber alerta de queda) e um **QR com a logo da empresa** no centro.

## Objetivos

- Tela de Canais igual ao Pencil: aviso "Sem failover entre números", cartões lado a lado (nome, telefone, selo de status, QR dentro do cartão, Engine/Sessão/Último evento, importar histórico, botões), cartão do Webhook de entrada no fim.
- Botões por canal: Conectar/Reconectar (ou "Gerar novo QR" quando aguarda leitura), Desconectar, **Desativar/Reativar**, **Excluir sessão** e **Apagar canal** (só vazio).
- QR desenhado no navegador com a logo no centro; se algo falhar, cai na imagem do WAHA.

## Não objetivos

- Responder uma conversa por outro número, aviso de mesmo contato em dois números: fatia 2.
- Upload de logo pela tela (a logo é um arquivo do app).
- Apagar conversas. Nenhuma ação desta fatia apaga ticket ou mensagem.

## Antiobjetivos (seria falha mesmo funcionando)

- Perder histórico de atendimento ao excluir uma sessão.
- Um QR bonito que o celular não lê, sem caminho de volta.
- Canal desativado de propósito continuar gritando alerta.

## Decisões do dono

- **Excluir sessão** remove a sessão no WAHA e mantém o canal e todos os tickets. Para voltar, adiciona a mesma sessão (mesmo nome) e conecta: ela reaproveita a mesma linha de canal, e os tickets voltam a funcionar. Um canal novo com **outro nome** não herda nada; o formulário de adicionar avisa isso.
- **Apagar canal** (remove a linha) só aparece para canal sem nenhum ticket e sem nenhuma mensagem, com confirmação.
- **Desativar** para a sessão no WAHA (como Desconectar) e marca `active = false`; **Reativar** volta `active = true`. Canal desativado não gera alerta de queda, não gera faixa vermelha e não conta na checagem "nenhum número conectado".
- Resposta num ticket de canal desativado: **bloqueada** com aviso "Canal desativado. Reative para responder."; o ticket continua legível.
- **Desativar e Excluir sessão** num canal com tickets abertos pedem confirmação com a contagem ("Este canal tem N tickets abertos…"), sem bloquear.
- QR: **no navegador** com `qr-code-styling` (MIT, 2,9 mil estrelas, push em fev/2026), texto cru via `GET /api/{session}/auth/qr?format=raw` (`{value}`), correção de erro H, logo menor que ~20% da área. Fallback: imagem PNG do WAHA.
- Logo: arquivo fixo do app, `public/images/qr-logo.svg`. A logo atual do app é a do starter kit do Laravel, não a da Dimetal: o dono entrega o arquivo; até lá vai um monograma "D" provisório, trocado só substituindo o arquivo.

## Abordagens consideradas

| Parte | Escolhida | Descartadas |
|---|---|---|
| QR | Navegador, `qr-code-styling` | `BaconQrCode` (PHP, SVG com logo composta à mão: mais código); manter o QR genérico |
| Logo | Arquivo fixo | Upload nas configurações (tela, validação e armazenamento novos) |
| Excluir | Remove no WAHA, mantém canal; apagar linha só se vazio | Só remover no WAHA; bloquear com tickets abertos (trava quando o número caiu) |

## Como se encaixa no código

- `Channel.active` já existe (padrão `true`). `AlertDispatcher::channelChanged` e `ConnectionState` já o respeitam. **Falta** `SidebarState::whatsappDown` (hoje conta canais inativos) e uma tela que o altere.
- `WahaClient` ganha `deleteSession()` (`DELETE /api/sessions/{name}`, 404 aceito) e `qrRaw(): ?string` (`?format=raw`, chave `value`; confirmar o JSON na versão do WAHA em uso).
- `ChannelCard::refreshStatus` hoje trata 404 como erro a cada 5 s; passa a tratar 404 como "sessão não existe no WAHA" (canal `STOPPED`, selo "Sessão removida" apenas na tela, sem coluna nova).
- Envio: `ComposesMessages`/`SendTicketMessage` recusam canal com `active = false`; a caixa de resposta mostra o aviso (como já faz para encerrado).
- O QR cru passa pelo estado do Livewire como já passa o PNG em base64 (mesma sensibilidade; some quando o canal conecta).

## O que fica para quem constrói

- Estrutura do JS (um módulo Alpine pequeno em `resources/js`), mantendo o CSP em `report-only` e o bundle enxuto (hoje 29,7 KB).
- Textos exatos dos avisos e das confirmações, no tom do resto do app.
- Cartão do Webhook: URL de `WahaSettings::webhookUrl()` e estado "recebendo" a partir do último evento.

## Verificação combinada

- Testes de feature para: excluir sessão (WAHA 204 e 404), manter tickets, reuso do canal ao readicionar, apagar canal só vazio, desativar (para a sessão, silencia alerta, fora do `whatsappDown`), bloqueio de resposta, confirmação com contagem.
- Leitura do QR com logo no celular do dono (único teste que prova a leitura) antes de dar a fatia por fechada.

## Fatia 2 (decisões já tomadas, spec próprio depois)

Dois números atendidos pela mesma equipe, mesmo contato (Google Contatos) podendo escrever pelos dois:
- Um ticket por número (como o índice atual já impõe); **aviso em cada ticket** quando o contato tem ticket aberto no outro número **ou conversa nos últimos 30 dias** lá, com link para o outro.
- O ticket mostra por qual número a conversa acontece (agentes se confundem sem isso).
- A resposta sai **sempre pelo número do ticket**; trocar de número fica de fora.
