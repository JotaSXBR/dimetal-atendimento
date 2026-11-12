# CSP enforce Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (inline, regra do projeto) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Política CSP completa (script com hash do Flux, `unsafe-eval`, `media-src blob:`, `upgrade-insecure-requests` em HTTPS) em todas as respostas, pronta para `enforce` no beta.

**Architecture:** `SecurityHeaders::policy()` ganha as diretivas; um helper `FluxAppearanceHash` calcula o hash da fonte do Flux; o middleware passa de grupo `web` para global.

**Tech Stack:** Laravel 13, PHPUnit, Flux 2, Chrome DevTools/Claude in Chrome, API do Coolify.

**Spec:** `docs/superpowers/specs/2026-11-12-csp-enforce-design.md`

## Global Constraints

- Só o beta; nunca a `main`/produção do Coolify; token do Coolify nunca impresso (ler com python; `|` no valor).
- Proibido `'unsafe-inline'` em `script-src` e hashear o HTML da resposta.
- O padrão de `config/security.php` continua `report-only` até validar no beta.
- Commits em português com prefixo; PR só com pedido do dono.

## Review Focus

- Atualização do Flux que mude o script de aparência: o teste do hash falha (não o tema em produção).
- Resposta 404 e 500 com os mesmos cabeçalhos de segurança que as páginas.
- HTTPS sem `upgrade-insecure-requests` ausente; HTTP sem ele (não quebra dev).
- `CSP_MODE=off` continua sem cabeçalho nenhum de CSP, mesmo no middleware global.
- Áudio (player e prévia da gravação) e vídeo tocando em enforce.

---

### Task 1: Política completa com hash do Flux

**Files:**
- Create: `app/Support/FluxAppearanceHash.php`
- Modify: `app/Http/Middleware/SecurityHeaders.php` (`policy()`)
- Test: `tests/Feature/Security/SecurityHeadersTest.php`

**Interfaces:**
- Produces: `FluxAppearanceHash::value(): string` (`'sha256-<base64>'`, do conteúdo do `<script>` de `Flux\AssetManager::fluxAppearance()`).

- [ ] **Step 1: RED.** Em `SecurityHeadersTest`: (a) `test_script_src_tem_o_hash_do_script_de_aparencia_que_a_pagina_renderiza`: pega `/login`, extrai o conteúdo do único `<script>` sem `src` do HTML, calcula `'sha256-'.base64_encode(hash('sha256', $conteudo, true))` e afirma que o cabeçalho (`Content-Security-Policy-Report-Only`) o contém; (b) `test_politica_libera_blob_so_para_midia_e_proibe_inline_em_script`: contém `media-src 'self' blob:`, contém `'unsafe-eval'`, **não** contém `'unsafe-inline'` dentro de `script-src` (extrair a diretiva e checar); (c) `test_upgrade_insecure_requests_so_em_https`: `get('http://localhost/login')` sem a diretiva, `get('https://localhost/login')` com ela. Atualizar o teste existente que afirma `assertStringNotContainsString('unsafe-eval', ...)` para a nova regra.
- [ ] **Step 2:** `php artisan test --filter=SecurityHeadersTest`; esperado: FAIL nos três novos.
- [ ] **Step 3:** Implementar `FluxAppearanceHash::value()` (extrai o corpo do `<script>` do retorno de `AssetManager::fluxAppearance()` com regex, calcula o hash, guarda em propriedade estática) e acrescentar em `policy()`: `script-src 'self' <hash> 'unsafe-eval'`, `media-src 'self' blob:` e `upgrade-insecure-requests` quando `$request->isSecure()`. Comentário de uma linha com o porquê do `unsafe-eval` (Alpine usa `new Function`; saída: `csp_safe` do Livewire, fora do escopo).
- [ ] **Step 4:** Mesmo comando; esperado: todos verdes.
- [ ] **Step 5:** `git add app/Support/FluxAppearanceHash.php app/Http/Middleware/SecurityHeaders.php tests/Feature/Security/SecurityHeadersTest.php && git commit -m "feat: CSP completa (hash do Flux, unsafe-eval, media-src blob, upgrade-insecure)"`

### Task 2: Cabeçalhos também nas respostas de erro

**Files:**
- Modify: `bootstrap/app.php:24`
- Test: `tests/Feature/Security/SecurityHeadersTest.php`

**Interfaces:**
- Consumes: `SecurityHeaders` (Task 1).

- [ ] **Step 1: RED.** `test_resposta_404_leva_os_mesmos_cabecalhos_de_seguranca`: `get('/rota-que-nao-existe')`, status 404, com `Permissions-Policy`, `Cross-Origin-Opener-Policy` e `Content-Security-Policy-Report-Only`.
- [ ] **Step 2:** rodar o teste; esperado: FAIL.
- [ ] **Step 3:** Em `bootstrap/app.php` trocar `$middleware->web(append: [SecurityHeaders::class]);` por `$middleware->append(SecurityHeaders::class);`.
- [ ] **Step 4:** `php artisan test --filter=SecurityHeadersTest` e os afetados (`git status --porcelain | cut -c4- | codegraph affected --stdin -d 2 --quiet | xargs -r php artisan test`); esperado: verde. Conferir que `CSP_MODE=off` segue sem cabeçalho (teste existente).
- [ ] **Step 5:** commit `fix: cabeçalhos de segurança em todas as respostas, inclusive erros`.

### Task 3: Verificação local em enforce no navegador

**Files:** nenhum de produto (scratchpad).

- [ ] **Step 1:** Subir `pg-test` e o servidor local (`dimetal_local`, porta 8123) com `CSP_MODE=enforce`.
- [ ] **Step 2:** Logado, percorrer com o Chrome DevTools: Fila, abrir conversa, Contatos, Histórico, Relatórios, Auditoria, Configuração (Empresa com recorte de avatar, Canais com QR), editor de automação; lendo `list_console_messages` (tipos `error` e `issue`) a cada tela. Esperado: nenhuma violação de CSP (o `ws://localhost:8080` recusado é do ambiente, sem Reverb).
- [ ] **Step 3:** Provar os fluxos que só aparecem com interação, escutando `securitypolicyviolation`: criar `Audio` com `URL.createObjectURL(new Blob(...))`, abrir o seletor de emoji, escolher arquivo no recorte de avatar. Esperado: zero violações; fora isso, registrar e voltar ao Task 1.
- [ ] **Step 4:** Registrar no ledger o resultado por tela.

### Task 4: Beta em enforce, variável e documentação

**Files:**
- Create: `docs/superpowers/reports/2026-11-12-csp-enforce.md`

**Interfaces:**
- Consumes: `DIMETAL_BETA_APP_UUID`, `COOLIFY_URL`, `COOLIFY_API` em `.env.coolify.local` (ler com python; nunca imprimir o token).

- [ ] **Step 1 (depois do merge, pedido ao dono):** com o código no beta (deploy-beta success), listar as variáveis do app beta pela API v1 (`GET /api/v1/applications/<uuid>/envs`), gravar `CSP_MODE=enforce` (`PATCH`/`POST` conforme já existir), reiniciar o app pela API. Anotar o valor anterior (ou ausência) para o rollback.
- [ ] **Step 2:** Pelo Claude in Chrome, no beta: conferir `Content-Security-Policy` no cabeçalho (curl) e percorrer as mesmas telas e fluxos do Task 3 (inclusive tocar um áudio real e gravar e ouvir a prévia); esperado: nenhuma violação.
- [ ] **Step 3:** Escrever `docs/superpowers/reports/2026-11-12-csp-enforce.md`: política final diretiva por diretiva com o porquê, a revisão de cabeçalhos do beta, a pesquisa (fontes), a variável alterada (antes/depois, data) e o rollback (`CSP_MODE=report-only` + reinício pela API), e o que fica para depois (build CSP do Alpine sem `unsafe-eval`, coletor de relatórios, HSTS `preload`).
- [ ] **Step 4:** Com a validação ok, trocar o padrão de `config/security.php` (`csp_mode`) para `enforce`, ajustar o teste `test_csp_comeca_em_modo_somente_relatorio_sem_bloquear` para o novo padrão e commitar `feat: CSP em enforce por padrão`.
