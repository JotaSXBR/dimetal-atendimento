# Menores, leva 2 (U2, U6, U10, U11, L1–L9, prazo de apagar): design curto

Data: 16/11/2026. Branch `fix/menores-leva-2`, a partir da `main` (`4a5ab24`).

## Por quê

O dono quer zerar o que sobrou em `docs/superpowers/menores-pendentes.md` depois da leva 1 (PR #129).

## Escopo (cada item que muda comportamento tem teste RED antes)

| Item | Decisão |
|------|---------|
| U2 | Fecha sem código: conferido no navegador em 16/11 (360 px, a página não rola de lado; a faixa rola por dentro). Sai do arquivo com o motivo. |
| U6 | Fecha sem código: prefixo `history-*` é só nome, sem conflito. Sai do arquivo. |
| U10 | Bolha com menu ganha largura mínima que caiba horário e botão lado a lado (ex.: `min-w-28`), para o chevron não cobrir o horário nas bolhas curtas; bolhas maiores não mudam. Conferir no navegador com o seed (`BolhasDeTamanhosVariados`). |
| U11 | Rascunho passa a guardar a data (`{text, at}` em JSON; ler também o formato antigo de texto puro). Ao abrir o Inbox, o navegador apaga os rascunhos do usuário com mais de **7 dias** e pergunta ao servidor, numa chamada, quais dos tickets com rascunho estão **encerrados**, e apaga esses (decisão do dono, abordagem A). |
| L1 | Testes da contagem de setores: `setor_id` string no draft; setor desativado e apagado fora da contagem do menu; setor fixo apagado: não conta. |
| L2 | Teste do enquadramento do editor: a lógica do `fitView` que for pura vai para `appearance.js` com teste; o resto fica coberto pela conferência visual. |
| L3 | Trava `restore-dispatch` de 30 s para 120 s (só vale se o processo morrer; é solta no fim). |
| L4–L6 | Estilo: `if/else` no lugar do ternário (`composer-mode.js`), `catch` com comentário (`ticket-details.js`), restaurar `window`/`document` no fim do teste (`storage-blocked.test.mjs`). |
| L7 | Seed: `UsuariosDeTeste` chamado sozinho garante os setores (chama o `SectorSeeder` se não houver); teste de "sobra etiqueta" confere etiqueta não aplicada de verdade; `sync` dos setores fica (aceito). |
| L8 | Span do SLA no cabeçalho com `whitespace-nowrap`. |
| L9 | Linha de metadados cortada ganha `title` com o texto inteiro. |
| Prazo | O aviso de apagar fora do prazo vem de `Message::REVOKE_WINDOW_HOURS` (sem "2 dias" fixo); testes do limite exato (48 h e 15 min, `gte` inclusivo). |

## Fora

Pendências maiores do arquivo (phpmd moderno, mobile); qualquer mudança de layout além do U10.

## Abordagens (U11, a única com escolha)

1. **Limpeza ao abrir o Inbox, com consulta ao servidor (escolhida pelo dono):** cobre encerramento por qualquer pessoa ou pelo bot; uma chamada leve por carregamento.
2. Apagar só quando o próprio usuário encerra, o resto vence em 7 dias: sem chamada extra, mas rascunho de ticket encerrado por outro fica até 7 dias.

## Ao construtor

O método do servidor recebe só ids numéricos (no máximo os do usuário), devolve só os que estão encerrados e que o usuário pode ver; JS no subconjunto CSP (`Alpine.data`), com try/catch no storage. Ao fechar cada item, tirá-lo de `menores-pendentes.md` no mesmo commit.
