# Menores adiados da Leva A (design curto)

Mudança pequena: três correções de robustez que ficaram adiadas no PR #92. Vão no mesmo PR do commit `chore: suíte com Redis em paralelo`.

## Intenção

Fechar três arestas conhecidas da entrega de imagens e da transferência de tickets, sem mudar comportamento visível.

## Abordagens

Cada item tem uma solução sensata; a única escolha real é onde pôr a regra:

1. **Cache do avatar/logo.** Alternativa descartada: corrigir `request()->query('v') === $versão` nos dois controllers. Escolhida: `AvatarStorage::response(string $path, bool $public, ?string $version)` decide `versioned` sozinho (`$version !== null && v === $version`), então a regra fica num só lugar.
2. **`If-None-Match`.** `AvatarStorage::response` compara o ETag sem o prefixo `W/` (comparação fraca vale nesse cabeçalho). `*` continua sem 304: o navegador não o manda e 200 é resposta válida; responder 304 sem checar o disco serviria objeto sumido.
3. **Transferir para setor recém-apagado.** Alternativa descartada: reler o setor sem trava. Escolhida: dentro da transação de `TransferTicket` e de `StartConversation`, reler o setor com `sharedLock()` sem os apagados e recusar se sumiu ou se `receivesTickets()` falhou. O `DeleteSector` já trava o setor com `FOR UPDATE`; o `FOR SHARE` o espera, e várias transferências para o mesmo setor não se bloqueiam.

## Não objetivos

- Fotos de contato só a partir da próxima mensagem e `img-src blob:` na CSP: não são menores (produto e leva C).
- Tema claro: QA da leva C.

## Provas

Cada item com RED antes do código: ETag `W/` devolve 304; URL sem `v` e sem versão gravada não recebe `immutable`; setor apagado entre a leitura e o `UPDATE` faz `TransferTicket` devolver `false` e `StartConversation` lançar `ConversationNotStarted`.

## Decisões minhas (custo se erradas)

- `*` sem 304: se o dono quiser, é uma linha a mais (e checar o disco).
- Mudar a assinatura de `AvatarStorage::response`: só `AvatarController` e `LogoController` chamam; o custo é nenhum fora deles.
