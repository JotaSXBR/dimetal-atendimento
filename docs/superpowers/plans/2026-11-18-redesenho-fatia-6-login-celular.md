# Redesenho, fatia 6: Login e celular — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tela de entrada (e as outras do layout de autenticação) no arranjo do Pencil, e o componente próprio `ui/field` trocado
pelo `flux:input` do free no login e na instalação; o celular segue a base já aplicada nas fatias 1–5.

**Architecture:** Markup de `resources/views/layouts/auth/simple.blade.php`, `resources/views/pages/auth/login.blade.php` e
`resources/views/livewire/setup/install.blade.php`; apaga `resources/views/components/ui/field.blade.php`. Nenhuma rota, regra ou
propriedade nova.

**Tech Stack:** Laravel 13, Livewire 4.4 (build CSP), Flux 2.21 free, Fortify, Tailwind 4, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-17-redesenho-ui-pencil-design.md` (fatia 6; tabela "Componentes próprios x Flux free").

## Global Constraints

- Só visual: nada some (status da sessão, erros de validação, "Manter conectado", o texto de ajuda, `data-test="login-button"`,
  `data-test="install-button"`, os campos e `wire:model` da instalação). Sem "Esqueci a senha".
- Tokens de `resources/css/app.css`; nenhuma cor crua nova; piso de 12 px; alvo mínimo 24 px. Build CSP: nenhuma expressão Alpine nova.
- Testes com `DB_DATABASE=fluxie_ui_test`; testes conferem estrutura e `data-*`.

## Decisões (não reabrir)

- Celular (Ruling): o app já segue a base em 390 px (fila, conversa e detalhes conferidos sem estouro em 18/11); o Pencil
  (`inygo`, `J6qCRZ`) foi redesenhado a partir do app. Nenhuma tarefa de código para o celular além da conferência da Task 3.
- Saem do desenho: "Esqueci a senha", "por 8 h", subtítulo "Uso interno - Distribuidora", CNPJ no rodapé, ícones e placeholders
  dentro dos campos (o app não tem; os placeholders `rafael@…`/`Sua senha` do frame são só ilustração e não entram).
- Flux free tem campo? Sim (`flux:input` com `label`, que já mostra `flux:error`): `ui/field` sai.

## Medidas do Pencil (`LytKF`; PNG em
`C:/Users/bkpdi/AppData/Local/Temp/claude/abad7b6d92ced975/0cc90b53-26aa-4a61-b219-6ddb491b1462/scratchpad/pen-f6/final/LytKF.png`)

| Parte | Medidas |
|---|---|
| Página | `bg-canvas`; marca no canto de cima à esquerda (`md:` em diante; no celular centralizada acima do cartão, como hoje): logotipo 32 px + nome 14 px peso 600, a 48 px das bordas (`p-6 md:px-12 md:pt-10`) |
| Cartão | centralizado, `max-w-[400px]`, `rounded-xl border border-line bg-surface p-7` (28 px); sem sombra crua (usar `shadow-xs` ou nenhuma) |
| Título | "Entrar" 20 px peso 600; subtítulo 12–13 px `ink-2` logo abaixo (gap 4 px); campos com `gap-4` |
| Botão | `flux:button variant="primary"` largura toda, 40 px |
| Ajuda | separador `border-t border-line` com `pt-5`; título "Sem acesso?" 12 px peso 600 `ink`; o texto de hoje em 12 px `ink-3` |
| Rodapé | `md:` em diante: duas pontas na base da página (`justify-between`, 12 px `ink-3`): nome da empresa à esquerda, "Todo acesso é registrado na auditoria" à direita; no celular, centralizado como hoje |

## Review Focus

- Erro de login (credencial errada, campo vazio) aparece embaixo do campo com o tom `danger` e `aria-invalid` (Task 1).
- Status da sessão (`session('status')`, ex.: senha redefinida) continua visível acima do formulário (Task 1).
- Instalação: os cinco campos com seus erros e `wire:model` (Task 2).
- Outras páginas do layout de autenticação (confirmar senha, desafio 2FA) seguem legíveis no arranjo novo (Task 1; conferência).
- 390 px: marca, cartão e rodapé sem rolagem lateral (conferência).

---

### Task 1: Layout de autenticação e login

**Files:**
- Modify: `resources/views/layouts/auth/simple.blade.php`, `resources/views/pages/auth/login.blade.php`
- Test: o teste que já renderiza `/login` em `tests/Feature/Auth/` (conferir o nome; ex. `AuthenticationTest.php`)

- [ ] **Step 1: Testes que falham**
  - `test_login_no_desenho`: `GET /login` mostra `data-auth-brand` com o nome da empresa, `data-auth-card`, os campos `email` e
    `password` como `flux:input` (`[data-flux-input]`), "Manter conectado", `data-test="login-button"`, o bloco
    `data-auth-help` com "Sem acesso?" e o texto de hoje, e `data-auth-footer` com "Todo acesso é registrado na auditoria"; não
    contém "Esqueci".
  - `test_login_com_erro_marca_o_campo`: POST com senha errada e seguir o redirect → o erro aparece e o input de e-mail tem
    `aria-invalid="true"`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** com as medidas; `flux:input name="email" :label type autocomplete autofocus required` (e o mesmo para
  a senha, `viewable` opcional só se já existir em outro formulário do app — senão não).
- [ ] **Step 4: Rodar e ver passar** — `--filter="Authentication|Login|TwoFactorChallenge|ConfirmPassword|Passkey"`.
- [ ] **Step 5: Commit** — `feat: login no arranjo do desenho com campos do Flux`

### Task 2: Instalação com `flux:input` e fim do `ui/field`

**Files:**
- Modify: `resources/views/livewire/setup/install.blade.php`
- Delete: `resources/views/components/ui/field.blade.php`
- Test: o teste que já renderiza a instalação (`tests/Feature/Setup/…`)

- [ ] **Step 1: Teste que falha** — `test_instalacao_usa_campos_do_flux`: os cinco campos são `[data-flux-input]` com os mesmos
  `name`/`wire:model`; com erro de validação (`code` errado), a mensagem aparece.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar**; apagar `ui/field` (sem outro uso: conferir com `grep -rn "ui.field" resources tests`).
- [ ] **Step 4: Rodar e ver passar** — `--filter="Setup|Install|ViewConventions"`.
- [ ] **Step 5: Commit** — `refactor: instalação com campos do Flux e sem o campo próprio`

### Task 3: Verificação da fatia (controlador)

- [ ] Portão 7 do `CLAUDE.md`; suíte com Redis (`redis-ui`, porta 6380, removido no fim).
- [ ] Conferência visual: login (e erro de login) em 720p, 1080p, 2K e 390 com toque, claro e escuro; fila, conversa e detalhes da
  Inbox em 390 com toque, claro e escuro, sem rolagem lateral.
