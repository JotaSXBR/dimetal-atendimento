# Levantamento: tirar o `unsafe-eval` da CSP (12/11/2026)

Só levantamento, nada foi implementado. Pergunta do dono: cabe em um ciclo completo (spec, plano, execução, revisão, merge)?
Resposta curta: **sim, cabe em um ciclo**, com o editor de automação como a maior parte do trabalho.

## O que o `unsafe-eval` cobre hoje

Só o Alpine do Livewire, que avalia as expressões dos atributos (`x-data`, `x-show`, `x-on:click`, `wire:*`...) com `new Function`.
Conferido por busca nos arquivos construídos: o bundle do app (`public/build/assets`) e o `livewire.csp.js` não têm `eval` nem
`new Function`; só o `livewire.js` normal tem (1 ocorrência). O Flux usa só `Alpine.data` nomeado, sem eval.

Saída oficial (Livewire 4, `docs/csp`): `config/livewire.php` `'csp_safe' => true` troca o Alpine pelo build CSP
(`livewire.csp.js`), que usa um parser próprio e dispensa o `unsafe-eval`.

## O que o build CSP aceita e rejeita (docs Alpine e Livewire, confirmado no spike)

- Aceita: aritmética, comparações, ternário, `!`, `&&`, `||`, `??`, concatenação, chamada de método com argumentos, `count++`,
  `x = 0`, `x-model`, objetos e arrays literais simples.
- Rejeita: funções de seta, template literals (crases), spread, destructuring, atribuição a propriedade (`a.b = 1`), acesso dinâmico
  `a[b]`, **encadeamento opcional `?.`**, globais do navegador (`JSON`, `localStorage`, `window`, `Notification`...), `new X(...)`,
  métodos declarados dentro de um `x-data` inline (`{ toggle() { ... } }`) e, na prática, várias instruções separadas por `;`.

## Spike descartável (local, revertido, nada commitado)

`csp_safe=true` e política sem `unsafe-eval`, percorrendo as telas e lendo o console. O parser aponta a expressão exata que falha,
o que torna o ciclo mecânico: corrigir até zerar os avisos `Alpine Expression Error`.

- **Todas as telas:** o sino (`bell.blade.php`, `:style` com template literal).
- **Inbox:** `x-data` inline com método `toggle()` (`index` e `inbox`), que derruba o painel de detalhes; `x-on:change` com atribuição a
  propriedade e duas instruções; transcrição; banner.
- **Empresa:** `x-data` com método `wrap()` (barra de formatação da assinatura).
- **Editor de automação (o grande):** `x-data="flowEditor($wire, JSON.parse('...'))"`. `JSON` não existe no parser, então o `flowEditor`
  inteiro não inicia e **39 avisos em cascata** derrubam a tela toda. Mais: `menu ? \`left:...\`` (template literal), 12 usos de
  `$wire.sim?.x` (painel Testar), `messages[item.key]`, `selected.data[field.key]` em `x-model` (acesso dinâmico).
- Canais, Horário, Setores (além do sino): sem outros avisos nas telas medidas; os demais itens abaixo vêm do escaneamento estático.
- Modal do Flux: abre normalmente (o código vendor não bloqueia).

## Escaneamento estático (todas as views `.blade.php`)

Atributos Alpine/Livewire avaliáveis: ~710 (667 no app, 43 no Flux). Com ao menos um problema: **17 arquivos, ~40 atributos**:

| Arquivo | Atributos | O que muda |
|---|---|---|
| `automation/editor.blade.php` | 14 | `JSON.parse` no `x-data`, `?.` do simulador, `[chave]` dinâmico em `x-model`, template literal, seta |
| `livewire/inbox/inbox.blade.php` | 7 | `x-data` com método, atribuição a propriedade, `;` |
| `livewire/inbox/transcript.blade.php` | 5 | `x-data` com métodos, seta |
| `livewire/settings/sectors.blade.php` | 4 | setas, `.then(...)`, `;` |
| `passkey-registration`, `passkey-verify` | 3 | `x-data` grande, seta |
| `company`, `two-factor-*` (2), `alerts` (2) | 7 | `x-data` com métodos, `new CustomEvent`, `async` |
| `bell`, `banner`, `index`, `quote-preview`, `business-hours-page`, `channel-card` | 6 | template literal, seta, atribuição, falsos positivos a conferir |
| Flux `modal/trigger.blade.php` (vendor) | 1 | objeto literal em `$dispatch(...)`; testado no spike, funciona |

O app **já usa o padrão certo** onde importa (`Alpine.data` em `attach-tray`, `audio-player`, `avatar-cropper`, `channel-qr`, `flow-editor`...). O
que falta é mover os `x-data` inline com lógica e as expressões fora do subconjunto para `Alpine.data` nomeado ou para métodos.

## Abordagem proposta (um ciclo)

1. `csp_safe=true` + remover `'unsafe-eval'` da política (a última tarefa, depois de tudo limpo).
2. Mover cada `x-data` inline com lógica para `Alpine.data('nome', ...)` em `resources/js` (já é o padrão do app). Passar dados do servidor
   por `data-*` ou `<script type="application/json">`, e não por `JSON.parse` dentro do atributo.
3. Trocar `?.` e `[chave]` por getters e métodos do componente (`simOutcome`, `setField(key, value)`); trocar setas por métodos nomeados.
4. Guardrail permanente: um teste (PHP ou Node) que varre as views por construções proibidas (o `scan.py` virou teste), para nenhuma
   expressão nova reintroduzir o problema.
5. Verificação: avisos `Alpine Expression Error` zerados em todas as telas, em `enforce` e sem `unsafe-eval`, no local e no beta, mais
   um roteiro de comportamento por componente (os testes automatizados atuais não cobrem o comportamento do Alpine).

## Esforço e risco

- **Tamanho:** ~10 a 14 tarefas (o editor concentra ~35%); mexe em ~17 views e ~10 arquivos JS; 10 arquivos de teste citam atributos
  Alpine (os atributos de `inbox` serão os mais afetados) e precisam acompanhar.
- **Maior risco:** regressão de **comportamento** que nenhum teste automatizado pega (o app não tem teste de navegador). Mitigação: o
  parser acusa a sintaxe na hora; roteiro manual por componente no beta; mudanças pequenas e commit por componente.
- **Risco menor:** vendor do Flux (testado) e bibliotecas (Cropper, emoji, Rete/Lit não usam eval).

## Vale a pena?

O ganho real é fechar a brecha de injeção de HTML com atributos Alpine (`x-init` executaria hoje). A superfície é pequena: o app tem **um**
`{!! !!}` (o SVG do QR do 2FA, gerado pelo servidor), e o resto sai escapado. Hoje a CSP já bloqueia script injetado, carga externa,
exfiltração, frames e `<base>`. Recomendação: fazer, mas **depois** do 2FA obrigatório, do backup e da exportação (que fecham riscos
maiores), e antes de abrir a produção a mais de uma empresa. Não é bloqueio de v1.
