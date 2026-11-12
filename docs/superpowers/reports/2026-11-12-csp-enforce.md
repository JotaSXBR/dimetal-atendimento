# CSP em enforce (12/11/2026)

Só o beta. Spec: `docs/superpowers/specs/2026-11-12-csp-enforce-design.md`; plano: `docs/superpowers/plans/2026-11-12-csp-enforce.md`.
Código: PR #103 (política) e o PR seguinte (padrão `enforce`).

## Política final

`SecurityHeaders::policy()` (`app/Http/Middleware/SecurityHeaders.php`), enviada em todas as respostas (middleware global):

| Diretiva | Valor | Por quê |
|---|---|---|
| `default-src` | `'self'` | tudo vem do próprio domínio |
| `script-src` | `'self'` `'sha256-…'` `'unsafe-eval'` | o hash libera só o script inline do `@fluxAppearance` (evita o piscar de tema); `unsafe-eval` é do Alpine (`new Function` nas expressões dos atributos). Sem `'unsafe-inline'`: um `<script>` injetado é bloqueado |
| `style-src` | `'self'` `'unsafe-inline'` | Flux, Livewire, Cropper e o editor (Lit) usam estilo inline; risco de XSS por estilo é bem menor |
| `img-src` | `'self'` `data:` `blob:` | avatares, QR (data) e prévia do recorte (blob) |
| `media-src` | `'self'` `blob:` | áudio do player e da prévia de gravação por `URL.createObjectURL`; sem isso o áudio ficaria mudo |
| `font-src` | `'self'` `data:` | |
| `connect-src` | `'self'` + `wss://<domínio>` | Reverb pelo mesmo domínio (derivado de `REVERB_PUBLIC_URL`) |
| `object-src` `'none'`, `base-uri` `'self'`, `form-action` `'self'`, `frame-ancestors` `'self'` | | sem plugins, sem `<base>` externo, sem formulário para fora, sem moldura externa |
| `upgrade-insecure-requests` | só em HTTPS | não quebra o desenvolvimento em http |

O hash vem da diretiva do Flux (`FluxAppearanceHash`), nunca do HTML da resposta. `SecurityHeadersTest` compara o hash do
cabeçalho com o `<script>` realmente renderizado: uma atualização do Flux que mude o script derruba o teste, não o tema.

## Revisão de cabeçalhos do beta (curl, 12/11)

Bom: HSTS 1 ano com `includeSubDomains`; `nosniff`; `X-Frame-Options: SAMEORIGIN`; `Referrer-Policy`; COOP e CORP; `Permissions-Policy`;
cookie de sessão `Secure; HttpOnly; SameSite=Lax`; cookie XSRF `Secure; SameSite=Lax`; nginx sem versão; sem `X-Powered-By`;
`/.env` e `/storage/` 403; `/horizon` e `/telescope` 404. Lacuna achada e corrigida: respostas de erro (404/500) saíam sem
`Permissions-Policy`, COOP/CORP e CSP (o middleware só rodava no grupo `web`); agora é global.
Fica de fora (decisão consciente): HSTS `preload` (compromisso de domínio), coletor de relatórios CSP (`report-uri`).

## Pesquisa

- Livewire 4, docs `csp`: `csp_safe=true` usa o build CSP do Alpine e dispensa `unsafe-eval`, mas as expressões complexas dos atributos
  passam a exigir `Alpine.data`. Com ~600 expressões em atributos e o Flux, ficou como endurecimento futuro.
- Flux: `@fluxAppearance` e `@fluxScripts` aceitam `nonce`, mas nonce por requisição é frágil com `wire:navigate`; por isso hash.
- `media-src` precisa listar `blob:` explicitamente (`*` não o cobre); hash e nonce não se aplicam a mídia; sem workers no app.

## Variável alterada no beta (Coolify)

| Quando | Recurso | Variável | Antes | Depois |
|---|---|---|---|---|
| 12/11/2026 14:58 UTC | app do beta (`DIMETAL_BETA_APP_UUID`) | `CSP_MODE` | ausente (valia o padrão `report-only`) | `enforce` (uuid da variável `vzxhpmv2jk858f8sva1wqtkc`; o Coolify criou também a cópia de preview) |

Reinício do app pela API (`POST /api/v1/applications/<uuid>/restart`). Worker, scheduler, Reverb e WAHA não servem páginas: sem mudança.
Depois do padrão `enforce` no código, a variável fica redundante (pode ficar; é o botão de rollback).

**Rollback:** no Coolify, `CSP_MODE=report-only` (ou `off`) no app do beta e reiniciar; sem deploy de código. Pela API:
`PATCH /api/v1/applications/<uuid>/envs` com `{"key":"CSP_MODE","value":"report-only"}` e o `restart`.

## Verificação

- Local (`CSP_MODE=enforce`, Chrome DevTools): Fila com conversa, Contatos, Empresa, editor de automação (62 elementos, estilizado), seletor de
  emoji (196 emojis), recorte de avatar (`cropper-canvas` com `blob:`), áudio e imagem `blob:`: zero violações; `<script>` injetado e imagem
  externa bloqueados; controle sem `blob:` em `media-src` bloqueia o áudio.
- Beta (`CSP_MODE=enforce`, Claude in Chrome): cabeçalho `Content-Security-Policy` com o mesmo hash do local; Fila carrega, tema escuro aplicado
  (script com hash executou), Alpine, Livewire, Flux e Echo ok, WebSocket `connected`, sem aviso de queda; bateria (áudio `blob:`, imagem `blob:`,
  eval do Alpine ok; script injetado, imagem externa e `fetch` externo bloqueados); recorte de avatar abriu e cancelou sem violação; editor de
  automação com 8 blocos e botões. `/up` 200.
- **Não verificado no beta**, para não gerar efeito em contatos reais: tocar áudio real, gravar e ouvir a prévia, emoji no compositor (abrir
  conversa). Mesmo código provado localmente e com os equivalentes sintéticos. Conferir à mão uma vez.
- CI do PR #103 verde. O job `audit` falhou por um aviso novo do `phpseclib` (4.0.2, PKSA-h7cz-q7zq-mdkt, de 02/11), sem relação com a CSP:
  atualizado para 4.0.3 no mesmo PR, commit próprio.

## Fica para depois

- Sem `'unsafe-eval'`: `livewire.csp_safe=true` + reescrever expressões dos atributos (ou `Alpine.data`) e conferir o Flux.
- Coletor de relatórios (`report-uri`/`report-to`), se quiser enxergar violações em produção.
- HSTS `preload`.
- Desenvolvimento local com `npm run dev` precisa de `CSP_MODE=report-only` (já no `.env.example`).
