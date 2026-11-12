# CSP: de somente-relatório para enforce

Data: 2026-11-12. Só o beta está em escopo. Design curto (mudança pequena: uma política, um helper, um registro de middleware).
Laravel 13, Livewire 4.4.8, Flux 2, Alpine (embutido no Livewire).

## Por que

O dono quer a CSP bloqueando de verdade antes da produção. Hoje ela só reporta (`CSP_MODE=report-only`) e ninguém coleta o
relatório. Pediu uma revisão ampla (cabeçalhos do beta, violações) e pesquisa online do que pudesse ter passado.

## O que a revisão achou

Beta (curl + Chrome) e código local (DevTools):
- Cabeçalhos bons: HSTS 1 ano com `includeSubDomains`, `nosniff`, `X-Frame-Options`, `Referrer-Policy`, COOP, CORP,
  `Permissions-Policy`; cookie de sessão `Secure; HttpOnly; SameSite=Lax`; nginx sem versão; `/.env` e `/storage/` 403;
  `/horizon` e `/telescope` 404.
- 16 telas do beta: 1 `<script>` inline por página, 0 manipuladores `onclick`, 0 `javascript:`, 0 origens externas.
- Impedimentos para o enforce: (1) `eval` do Alpine em `livewire.js` (`new Function` nas expressões de `x-show`, `x-text`...);
  (2) o `<script>` inline do `@fluxAppearance`; (3) **áudio por `blob:`** (`URL.createObjectURL` no player e na prévia da
  gravação) cai em `default-src 'self'` por falta de `media-src`; (4) respostas de erro (404/500) não passam pelo middleware,
  então saem sem `Permissions-Policy`, COOP/CORP e CSP.
- Não é problema: `wss://` do Reverb (derivado da `public_url`); mídia (sai pelo app: o servidor busca no RustFS e repassa, sem
  URL externa no navegador); links `wa.me` e Google Maps (navegação, a CSP não os governa); sem workers no app (`worker-src`
  cai em `script-src`).

## Pesquisa

- Livewire 4 (docs `csp`): `csp_safe=true` troca o Alpine pelo build CSP e dispensa `unsafe-eval`, mas as expressões
  complexas em atributos deixam de funcionar (usar `Alpine.data`).
- Flux: `@fluxAppearance` e `@fluxScripts` aceitam `nonce`; o script de aparência é estático.
- `media-src` precisa listar `blob:` explicitamente (`*` não o cobre); hash/nonce não se aplicam a mídia.
- Nonce por requisição com `wire:navigate` é o ponto frágil (a política da primeira carga vale para as páginas trocadas):
  por isso hash, que é estável.

## Objetivos, não-objetivos, anti-objetivos

- Objetivo: `Content-Security-Policy` (enforce) no beta sem nenhuma violação nos fluxos reais, com volta atrás por variável.
- Não-objetivo: remover o `unsafe-eval` (build CSP do Alpine, ~600 expressões em atributos; fica como endurecimento futuro) e
  coletor de relatórios (`report-uri`).
- Anti-objetivo: hashear o HTML da resposta ou liberar `'unsafe-inline'` em script (anularia a proteção contra injeção).

## Abordagens (o dono escolheu A)

- **A:** `script-src 'self' 'sha256-<Flux>' 'unsafe-eval'`, `media-src 'self' blob:`, `upgrade-insecure-requests` só em
  HTTPS, middleware global para os erros. Bloqueia script injetado, carga externa, exfiltração, frames e `<base>`; a fraqueza é
  injeção de HTML com atributos Alpine (`x-init`), que ainda executaria.
- B: sem `unsafe-eval` via Alpine CSP. Alto risco de regressão logo depois de fechar a UX.
- C: ficar em report-only. Não protege.

## Decisões minhas (o dono pode reverter)

- Hash em vez de nonce (`wire:navigate`). Calculado em tempo de execução da **fonte do Flux**
  (`Flux\AssetManager::fluxAppearance()`), com teste que compara o hash do cabeçalho com o script realmente renderizado: se o
  Flux mudar o script numa atualização, o teste falha em vez de o enforce quebrar o tema.
- O padrão do código continua `report-only` neste PR; o beta recebe `CSP_MODE=enforce` pela API do Coolify (registrado em
  `docs/superpowers/reports/2026-11-12-csp-enforce.md`); o padrão vira `enforce` num commit seguinte, depois de validar no beta.
- `upgrade-insecure-requests` só quando a requisição é segura (não quebra o desenvolvimento em http).
- Voltar atrás: `CSP_MODE=report-only` (ou `off`) no Coolify e reiniciar; sem deploy de código.

## Prova de pronto

Testes verdes (política, hash igual ao script renderizado, erros com cabeçalhos); no navegador local e no beta, em enforce,
nenhuma violação nas telas e nos fluxos: Fila, conversa, tocar áudio, gravar e ouvir a prévia, recortar avatar, emoji, editor de
automação, QR do canal.
