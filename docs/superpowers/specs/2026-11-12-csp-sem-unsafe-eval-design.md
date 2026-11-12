# CSP sem `unsafe-eval`: Alpine no build CSP

Data: 2026-11-12. Só o beta está em escopo. Projeto médio (17 views, ~10 componentes JS). Laravel 13, Livewire 4.4.8 (Alpine 3.15), Flux 2.
Base: `docs/superpowers/reports/2026-11-12-csp-sem-unsafe-eval-levantamento.md` (levantamento e spike) e
`docs/superpowers/reports/2026-11-12-csp-enforce.md`.

## Por que

A CSP do beta está em `enforce`, mas com `script-src ... 'unsafe-eval'`, porque o Alpine do Livewire avalia as expressões dos atributos
com `new Function`. Com isso, uma injeção de HTML com atributos Alpine (`x-init="..."`) executaria. O dono quer fechar essa brecha
(decisão de 12/11, depois de descartar o 2FA obrigatório por enquanto).

## O que a leitura do parser instalado mostrou

Lido em `vendor/livewire/livewire/dist/livewire.csp.js` (verdade do que roda), corrigindo parte do levantamento:

- **Aceita:** literais (`string`, número, `true/false/null/undefined`, objeto e array simples), `||`, `&&`, `==`, `!=`, `===`, `!==`,
  `<`, `>`, `<=`, `>=`, `+ - * / %`, `!`, `-`, `+`, `++`/`--`, ternário `a ? b : c`, acesso `a.b` e `a[expr]`, chamada com argumentos
  (`f(x, y)`, `a.b(x)`), atribuição `=` a variável ou a propriedade de objeto comum, parênteses.
- **Rejeita:** `;` entre instruções (uma expressão só, `;` final opcional), `if`, `const`/`let`, `return`, `await`/`async`, funções de seta,
  `function`, template literals, spread, `?.`, `??`, `new`, `typeof`, `in`, método declarado dentro de `x-data` inline
  (`{ toggle() {...} }`), globais do navegador (`JSON`, `localStorage`, `window`, `document`, `Math`, `Date`, `Notification`,
  `navigator`, `console`, `setTimeout`), atribuição a propriedade de **nó do DOM** (`$el.style.x = ...`, `$event.target.value = ''`),
  e as chaves `constructor`, `prototype`, `setAttribute`, `appendChild`, `insertBefore`, `createElement`...
- Em `x-on:*` a expressão só é interpretada **no momento do evento**: o spike (que só vê o que roda ao carregar) subestima os
  manipuladores. Por isso o guarda é estático.

## Objetivos, não-objetivos, anti-objetivos

- Objetivo: nenhuma expressão de atributo Alpine/Livewire fora do subconjunto; com `LIVEWIRE_CSP_SAFE=true` o app inteiro funciona sem
  `Alpine Expression Error` e a política sem `'unsafe-eval'`.
- Não-objetivo: mudar comportamento ou visual; remover o 2FA/passkeys; trocar bibliotecas.
- Anti-objetivo: reescrever com `eval`/`Function` em JS próprio para "contornar" o parser (anula o ganho); desligar o guarda para passar.

## Abordagem (decidida: dono escolheu "PR único com chave" e "converter o editor com cuidado")

Uma chave, `LIVEWIRE_CSP_SAFE` (padrão `false`), liga o build CSP do Livewire **e** tira `'unsafe-eval'` da política (um único ponto de verdade:
`config('livewire.csp_safe')`). As conversões funcionam nos dois modos, então o merge não muda nada no beta; depois do merge eu ligo a chave no
beta pelo Coolify (como `CSP_MODE`), testo, e viro o padrão num PR pequeno.

Padrão de conversão: `x-data` com lógica vira `Alpine.data('nome', ...)` em `resources/js` (o padrão que o app já usa: `attachTray`,
`audioPlayer`, `avatarCropper`, `channelQr`, `flowEditor`); dados do servidor vão por `data-*`/JSON, não por `JSON.parse` no atributo;
várias instruções viram um método; `?.`, `??` e `if` viram métodos ou getters; atribuição a DOM vira método.

Alternativas descartadas: `csp_safe` ligado já no PR (sem rollback rápido); dois PRs (mais revisão para o mesmo risco, dado que a chave
já isola); teste de navegador (Playwright) agora (fora do escopo, o guarda estático + roteiro cobrem sintaxe, o comportamento fica no roteiro).

## Decisões minhas (o dono pode reverter)

- **Guarda estático permanente** (`tests/Unit/AlpineCspSubsetTest.php`): varre `resources/views` e falha com arquivo:linha para qualquer
  expressão fora do subconjunto. Nasce com uma lista de arquivos pendentes (baseline) que cada tarefa esvazia; no fim a lista é vazia e o
  teste cobre tudo. É o RED de todo o trabalho.
- **Chave única** `LIVEWIRE_CSP_SAFE`; o teste da política cobre os dois estados.
- **Passkeys e 2FA:** converto e verifico o que dá no Chrome local (modal, QR, código TOTP gerado por script a partir do segredo de teste);
  o prompt de passkey do sistema fica para o dono conferir no beta, depois do merge, num roteiro de 2 passos.
- **O que não toco:** `{!! $qrCodeSvg !!}`, bibliotecas (Cropper, emoji, Rete/Lit), o Flux (vendor; o spike mostrou o modal funcionando).
- Textos de `wire:confirm`, `wire:model="days.x.y"` e props Blade (`:prop="php"`) em componentes não são expressões Alpine: ficam fora do guarda.

## Prova de pronto

Guarda verde com lista vazia; testes e portão 7 verdes; com `LIVEWIRE_CSP_SAFE=true` no servidor local, todas as telas sem
`Alpine Expression Error` e o roteiro de comportamento de cada componente passando no Chrome (Inbox com conversa, compositor, menu da
mensagem, editor com Testar, Setores, Empresa, alertas, sino, 2FA); PR com CI verde. Beta e padrão ficam para depois do merge.

## O que fica para depois

Ligar a chave no beta e virar o padrão (PR pequeno); conferência manual dos passkeys pelo dono; teste de navegador automatizado.
