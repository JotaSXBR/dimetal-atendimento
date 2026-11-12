# CSP sem `unsafe-eval` (12/11/2026)

Spec: `docs/superpowers/specs/2026-11-12-csp-sem-unsafe-eval-design.md`. Plano: `docs/superpowers/plans/2026-11-12-csp-sem-unsafe-eval.md`.
Levantamento que originou o trabalho: `2026-11-12-csp-sem-unsafe-eval-levantamento.md` (a gramática lá está corrigida abaixo).

## O que mudou

- **Uma chave:** `LIVEWIRE_CSP_SAFE` (padrão `false`, `config/livewire.php`). Ligada, o Livewire serve o build CSP do Alpine
  (`livewire.csp.js`, que não avalia strings) **e** a política deixa de liberar `'unsafe-eval'` (`SecurityHeaders`). Um único ponto de verdade:
  `config('livewire.csp_safe')`. Desligada, o app é idêntico ao de antes.
- **Guarda estático permanente:** `tests/Unit/AlpineCspSubsetTest.php` varre todas as views e falha com `arquivo:linha` para qualquer expressão
  de atributo Alpine/Livewire fora do que o parser aceita. Tem 30 casos de amostra do próprio scanner, e a lista de exceções (`PENDENTES`) está vazia.
- **17 views convertidas** (≈ 40 atributos) para `Alpine.data` em `resources/js` (arquivos novos: `alert-ui`, `ticket-details`, `message-menu`,
  `settings-ui`, `auth-ui`, `shown`), mais métodos novos em `composer-mode`, `attach-tray`, `audio-player` e `flow-editor`.
- `shown.js`: no build CSP o `x-show` aplica o `display` ~4 ms **depois** do `$nextTick` (medido), o que deixava sem foco o campo de renomear
  setor e a busca do emoji. Foco e medidas de elemento recém-exibido passam a esperar até 12 quadros; no Alpine normal a espera é nula.
  Aplicado em setores, emoji, menu da mensagem, recorte de avatar, menu do editor, passkeys e 2FA.

## Gramática real do parser (corrige o levantamento)

Lida em `vendor/livewire/livewire/dist/livewire.csp.js`:
- **Aceita:** literais (string, número, booleano, `null`, objeto e array simples), `||`, `&&`, comparações, `+ - * / %`, `!`, `++`/`--`,
  ternário, `a.b`, `a[x]`, chamada com argumentos, atribuição `=` a variável **ou propriedade de objeto comum**, `x-model` com chave dinâmica.
- **Rejeita:** `;` entre instruções, `if`/`const`/`let`/`return`/`await`, seta, `function`, crase, spread, `?.`, `??`, `new`, `typeof`, **`in`**
  (fora do `x-for`), método dentro de `x-data` inline, globais do navegador (`JSON`, `localStorage`, `window`, `Math`...), atribuição a
  propriedade de **nó do DOM** (`$el.style.x = ...`, `$event.target.value = ''`).
- `@js($array)` vira `JSON.parse('...')` e quebra (`JSON` não existe); string, número e booleano são literais e valem. O guarda só deixa passar
  os usos escalares conhecidos (`JS_ESCALARES`).
- `wire:click="delete(id)"` funciona: o Livewire prefixa identificadores soltos com `$wire.` antes de chegar ao Alpine (conferido no código e
  no navegador, apagando uma etiqueta). `wire:model="a.b.c"` não é expressão JS.
- Só os manipuladores `x-on:*` são interpretados **no evento**: por isso o guarda é estático, e o spike de carregamento subestima.

## Como ligar no beta e como voltar

Ligar (como foi feito com `CSP_MODE`, ver `2026-11-12-csp-enforce.md`): variável `LIVEWIRE_CSP_SAFE=true` no app do beta (Coolify, API
`POST /api/v1/applications/<uuid>/envs`) e reiniciar (`POST .../restart`). **Voltar:** `LIVEWIRE_CSP_SAFE=false` (ou apagar a variável) e reiniciar;
não precisa de deploy. Quando validado, trocar o padrão em `config/livewire.php` para `true` num PR pequeno (e `.env.example`).

## Verificação feita (local, chave ligada, CSP em `enforce`, Chrome)

Todas as telas sem `Alpine Expression Error`: Fila (com conversa), Contatos, Histórico, Relatórios, Auditoria, Automação (lista e editor),
as 9 de Configuração, Perfil, Segurança, Aparência, Alertas. E por comportamento:
- Inbox: painel de detalhes (recolher/reabrir e lembrar), menu da mensagem (posição, copiar, adicionar às notas, editar/cancelar/salvar),
  compositor (rascunho, mic vira Enviar, Enter e Shift+Enter, respostas rápidas e Esc devolve o foco, anexo), emoji (busca com foco).
- Editor: carga pelo `data-config`, seleção de bloco, `x-model` de chave dinâmica, mensagens do bot, painel Testar (sessão, resposta, trilha),
  menu de clique direito, Validar.
- Configuração: renomear setor (foco, Esc, Cancelar, Salvar), barra de formatação da assinatura, prévia dos sons, recorte de avatar, apagar etiqueta.
- Segurança: passkey (formulário com nome padrão e foco), 2FA de ponta a ponta (modal, QR, copiar chave, código TOTP gerado do segredo do banco
  de teste, ativação) e o desafio no login (foco, alternar para recuperação, entrada com o código).

## O que NÃO foi exercitado (conferir no beta, depois de ligar a chave)

- **Passkeys com o sistema:** o prompt do Windows Hello/celular para criar e usar um passkey (não dá para simular). Roteiro de 2 passos
  (~3 min): em Segurança, "Adicionar chave de acesso" e criar uma; depois, na tela de confirmar senha ou no login, "Confirmar/Entrar com chave de acesso".
- Balão do navegador nos alertas (pedido real de permissão de notificação).
- Banner "WhatsApp voltou" (só aparece após queda e volta do WhatsApp; código é só um temporizador).
- Arrastar/ligar blocos, Salvar rascunho, Publicar e Desfazer no editor (Rete e métodos JS que não mudaram).
- Gravação de voz pelo microfone.

## Riscos e decisões

- Com a chave ligada o `Alpine.data` precisa estar registrado antes do `x-data` avaliar: os componentes novos estão todos no bundle `app.js` (carregado
  em toda tela), registrados em `alpine:init`.
- `shown.js` espera no máximo ~200 ms (12 quadros) por um elemento que nunca aparece; custo só com a chave ligada.
- O guarda depende da gramática do Livewire/Alpine instalados (4.4.8 / 3.15). Uma atualização que mude o parser pode exigir ajustar as regras
  (os 30 casos de amostra do próprio guarda ajudam a perceber).
