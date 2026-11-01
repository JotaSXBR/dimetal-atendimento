# Traduções pt_BR pelo pacote oficial — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução inline, regra do `CLAUDE.md`). Steps use checkbox (`- [ ]`) syntax.

**Goal:** `lang/pt_BR` vem do pacote oficial `laravel-lang`, com todas as chaves de validação do framework traduzidas e sem perder o que é próprio do app.

**Architecture:** `laravel-lang/lang` e `laravel-lang/publisher` em `require-dev`; `php artisan lang:add pt_BR` publica os arquivos, que ficam commitados. Um teste compara as chaves de `validation.php` do framework com as do pt_BR.

**Tech Stack:** Laravel 13, Composer, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-01-laravel-lang-design.md`

## Global Constraints

- Só o locale `pt_BR`; `locale=pt_BR` e `fallback_locale=en` não mudam.
- Pacotes só em `require-dev`; arquivos de idioma publicados vão no git (a imagem de produção usa `--no-dev`).
- Não perder as 97 chaves de `lang/pt_BR.json` nem os `attributes` de `lang/pt_BR/validation.php` (`name`, `email`, `password`, `password_confirmation`, `current_password`, `code`, `recovery_code`).
- Commits em português com prefixo; branch `feat/laravel-lang`; sem advisor/subagentes.

## Review Focus

1. Chave própria do app ou `attributes` sobrescritos pelo publicador (Task 1 restaura e o `TranslationTest` pega).
2. Mensagem de login errado e de campo obrigatório continuam em português (Task 2).
3. `composer install --no-dev` ainda funciona e o `composer.lock` fica consistente (Task 3).

---

### Task 1: Cobertura das chaves de validação e publicação do pt_BR

**Files:**
- Test: `tests/Feature/Layout/TranslationTest.php` (novo método)
- Modify: `composer.json`, `composer.lock`
- Modify (publicados): `lang/pt_BR/*.php`, `lang/pt_BR.json`

- [ ] **Step 1: Write the failing test** `test_validation_cobre_todas_as_chaves_do_framework`: carrega `vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php` e `lang/pt_BR/validation.php`; achata as chaves em notação de ponto (ignorando `attributes` e `custom`); exige que todas as do framework existam no pt_BR.
- [ ] **Step 2: Run** `php artisan test tests/Feature/Layout/TranslationTest.php --filter=validation_cobre` — Expected: FAIL (faltam chaves).
- [ ] **Step 3: Implement.** `composer require --dev laravel-lang/lang laravel-lang/publisher`; `php artisan lang:add pt_BR`. Conferir `git diff` em `lang/`: se `pt_BR.json` perdeu chaves próprias ou `validation.php` perdeu `attributes`, restaurar do git (`git checkout -- <arquivo>`) e mesclar as chaves próprias de volta à mão.
- [ ] **Step 4: Run** `php artisan test tests/Feature/Layout/TranslationTest.php --filter=validation_cobre` — Expected: PASS.
- [ ] **Step 5: Commit** `feat: traduções pt_BR pelo pacote oficial laravel-lang`.

### Task 2: Testes de login e validação com o texto do pacote

**Files:**
- Modify: `tests/Feature/Layout/TranslationTest.php` (`test_login_com_senha_errada_responde_em_portugues`, `test_validacao_em_portugues`)

- [ ] **Step 1:** Rodar `php artisan test tests/Feature/Layout/TranslationTest.php` — Expected: falha só onde o texto exato mudou (se não falhar, registrar e ir ao Step 4).
- [ ] **Step 2:** `test_login_com_senha_errada...`: a mensagem deve ser igual a `trans('auth.failed')` e não conter `credentials`. `test_validacao_em_portugues`: a mensagem de `email` deve ser igual a `trans('validation.required', ['attribute' => 'e-mail'])` e não conter `field is required`.
- [ ] **Step 3: Run** o mesmo comando — Expected: PASS.
- [ ] **Step 4: Commit** `test: mensagens de login e validação seguem o idioma do pacote`.

### Task 3: Verificação, PR e merge

- [ ] **Step 1:** `QUEUE_CONNECTION=database composer test` (Pint, Larastan, PHPMD, testes) e `composer install --no-dev --dry-run` — Expected: tudo passa.
- [ ] **Step 2:** Revisão do branch inteiro por mim (sem advisor) via `superpowers:requesting-code-review`; achados críticos viram teste + correção.
- [ ] **Step 3:** `git push -u origin feat/laravel-lang`; abrir PR em português contra `main`; esperar os checks; `gh pr merge --squash --delete-branch` (autorizado pelo dono).
