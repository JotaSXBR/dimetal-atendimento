# Menores baratos (B1–B5, U1–U12, clique duplo da restauração): design curto

Data: 16/11/2026. Aprovado pelo dono no chat. Branch: `chore/claude-md`, por escolha do dono (o mesmo PR leva o CLAUDE.md enxuto).

## Por quê

O dono quer zerar agora os achados de `docs/superpowers/menores-pendentes.md` que custam poucas linhas e um teste. Os que pedem
desenho novo ficam para outra leva.

## Escopo (cada item com teste RED antes da correção)

| Item | Causa encontrada | Correção |
|------|------------------|----------|
| B5 | O card do canal tem `wire:poll.5s`; o morph do Livewire tira o `open` do `<details>` "Mais opções" a cada poll | Preservar o estado aberto do `<details>` entre os polls (ex.: `wire:ignore.self`), sem parar o poll do status |
| B1 | Remover o chip apaga o botão focado e o foco vai para o `BODY` | Devolver o foco ao seletor "Adicionar…" depois de remover, em `inbox/ticket-tags` e em `contacts/contact-card` (mesmo padrão), dentro do subconjunto CSP do Alpine |
| B4a | `Automation\Index::summary` conta o Menu de setores como 1 | Contar os setores que o menu mostra (`Sector::visibleInMenu`) unidos aos fixos dos blocos Transferir, sem repetir |
| B4b | A barra flutuante do editor cobre o bloco Início ao enquadrar | Enquadrar com margem que desconte a barra |
| B3 | Ao abrir Detalhes, as ações do cabeçalho se reorganizam e o "Encerrar" cai sob o cursor | Investigar; entra só se for ajuste de CSS/layout. Se crescer, volta para o arquivo de menores |
| Restauração | Clique duplo pode despachar 2 jobs | Botão desabilitado durante o pedido e trava no servidor (o segundo pedido não despacha outro job) |
| U1–U12 | Menores antigos da UX, talvez já resolvidos | Reconferir cada um no código. Se ainda existe e é barato, corrige com teste; senão, sai do arquivo com o motivo |

**B2 fecha sem código:** só a recuperação manual avisa com 0 mensagens (`notifyAlways`, coberto por
`test_manual_sem_nada_novo_avisa`); os alertas vistos vieram de cliques em "Recuperar".

## Fora

Aviso do lock do backup diário, canal desativado que reconecta, performance (miniatura e sino), limpeza do cache após a
restauração.

## Abordagens

1. **Um branch, um plano com uma tarefa por item, um PR (escolhida).** Pouco código por item; uma revisão final cobre tudo.
2. Um PR por item: oito ciclos de revisão e CI para mudanças de poucas linhas.
3. Corrigir sem plano, como ajuste trivial: não vale, porque vários itens mudam comportamento e pedem TDD.

## Ao construtor

Seletores e nomes exatos de cada correção; a forma de preservar o `<details>` e o foco, desde que passem no
`AlpineCspSubsetTest` e no `ViewConventionsTest`. Ao fechar cada item, tirá-lo de `menores-pendentes.md` no mesmo commit.
