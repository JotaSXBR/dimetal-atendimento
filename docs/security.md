# Segurança

Linha de base de segurança do app, o que está decidido e como mudar cada controle. Os limites de qualidade de código
(tipos, complexidade, cobertura, auditoria de dependências) estão no `README.md`.

## O que já protege

| Controle | Onde | Observação |
|---|---|---|
| HTTPS e HSTS (1 ano) | Traefik e nginx da imagem | `includeSubDomains` |
| `X-Frame-Options`, `nosniff`, `Referrer-Policy` | nginx da imagem | |
| Cookies `Secure`, `HttpOnly`, `SameSite=lax` | `config/session.php` | verificado no beta |
| CSRF | Laravel e Livewire | só o webhook do WAHA é isento, e ele exige HMAC |
| Webhook do WAHA | HMAC SHA-512 (`hash_equals`), dedupe por id | 401 sem assinatura válida |
| Autorização | `TicketPolicy` e escopo `visibleTo` | toda ação do Livewire chama `authorize` |
| Login | Fortify, 5 tentativas por minuto por e-mail e IP, só usuário ativo | cadastro público fechado |
| Senha | mínimo de 12, maiúscula, número, símbolo e checagem de vazamento em produção | |
| Canais privados (Reverb) | `routes/channels.php`, origens permitidas | só usuário ativo |
| Segredos | `.env*` ignorados pelo git; nenhum no repositório | rotacionar antes de purgar, se vazar |
| Dependências | `composer audit` e `npm audit` no CI, Dependabot | |
| Análise estática | Larastan `max`, PHPMD | |

## Cabeçalhos e CSP

`App\Http\Middleware\SecurityHeaders` envia `Permissions-Policy`, `Cross-Origin-Opener-Policy`,
`Cross-Origin-Resource-Policy` e a **Content-Security-Policy**. A política só aceita o próprio domínio
(`script-src 'self'`, sem `unsafe-eval`), proíbe `object`, fixa `base-uri`, `form-action` e `frame-ancestors`, e libera
no `connect-src` apenas o websocket do Reverb.

**Resultado da varredura no beta (28/10/2026).** A página em si não gera violação, e o websocket do Reverb conecta. As
**interações** geram violações de `script-src` por `eval`: o Alpine, que o Livewire e o Flux usam, avalia expressões com
`new Function`. Em `report-only` isso só é registrado; **em `enforce` quebraria o menu do usuário e os demais componentes
com Alpine**. Por isso a política continua em `report-only`.

**Próximo passo para poder bloquear, sem afrouxar `script-src`:** o Livewire 4.4.8 já traz o build compatível com CSP
(`livewire.csp.min.js`, ligado por `'csp_safe' => true` em `config/livewire.php`, hoje `false`) e o Flux 2.21 aceita `nonce`
nos scripts inline. Falta testar se os componentes do Flux funcionam com o build seguro de CSP (expressões inline em
`x-data` não são permitidas nele). Fazer isso no beta, com o console aberto e clicando em tudo, antes de qualquer `enforce`.

A CSP começa em `report-only`: o navegador **registra** as violações no console, sem bloquear nada. Para virar bloqueio:

1. Abrir o app no navegador com o console aberto e navegar por login, inbox e canais. Nenhuma linha
   "Content-Security-Policy-Report-Only" deve aparecer. Se aparecer, ajustar a política em `SecurityHeaders` (por exemplo,
   o Alpine do Livewire pode pedir a versão compatível com CSP) antes de seguir.
2. No Coolify, definir `CSP_MODE=enforce` no `app-beta` e reiniciar.
3. Repetir a navegação e conferir que nada quebrou. Para desligar rápido: `CSP_MODE=report-only` ou `off`.

## Proxies confiáveis

O app só lê o IP e o protocolo do cliente (`X-Forwarded-*`) de proxies nas faixas privadas da rede Docker
(`bootstrap/app.php`). Quem chegar direto a um container de fora delas não consegue forjar o próprio IP, que alimenta o
limite de tentativas do login. A lista é literal porque a configuração ainda não existe quando o middleware é montado.

## Limites de requisição

| Alvo | Limite | Onde |
|---|---|---|
| Login | 5 por minuto por e-mail e IP | `FortifyServiceProvider` |
| Webhook do WAHA | 300 por minuto por IP | `AppServiceProvider`, `config/security.php` |
| Envio de mensagem pelo painel | 30 por minuto por usuário | `Inbox::send`, `config/security.php` |

Todo o tráfego do WAHA chega do mesmo IP interno, então o teto do webhook é um freio contra inundação. O envio conta
só mensagens: o indicador "digitando" e a gravação de áudio, quando existirem, terão limite próprio e mais folgado
(ver `docs/superpowers/notes/2026-10-28-waha-presenca-e-midia.md`).

## Decisões em aberto

- **2FA obrigatório** para admin e supervisor: o recurso (2FA e passkey) existe e está disponível, mas **não é exigido**
  (decisão do dono, 28/10/2026). Recomendação para antes de subir a produção: exigir de admin e supervisor.
- **Enforce da CSP**: depende do teste do build compatível com CSP descrito acima.

## Varreduras automáticas

Rodam fora do workflow `tests` de propósito: o `deploy-beta` depende do `tests`, e uma varredura instável não pode travar o
deploy. Ações fixadas por SHA de commit e permissão mínima (`contents: read`).

| Varredura | Quando roda | O que reprova |
|---|---|---|
| Segredos no histórico do git (gitleaks, imagem fixada por digest) | todo PR, push na `main`, semanal | qualquer achado. **Segredo real: parar, rotacionar e avisar. Nunca liberar.** Falso positivo só por impressão digital, em `.gitleaksignore` |
| Imagem Docker (Trivy, alvo `runtime`) | semanal, sob demanda e PR que mexe em `docker/` ou nos lockfiles | CRÍTICA ou ALTA **com correção publicada** (o que não tem correção não é acionável) |
| Scan dinâmico passivo (OWASP ZAP baseline) contra o beta | semanal e sob demanda | nada por enquanto: só relata, até a primeira rodada ser triada |

Rodar na hora: `gh workflow run security` e `gh workflow run security-scan`.

**Pendente, depende do dono do repositório:** a revisão de dependências nos PRs (`actions/dependency-review-action`, reprovando
`high`) exige o **Dependency graph** ativado no GitHub (Settings → Advanced Security). Está desligado, e a action reprova com
"Dependency review is not supported on this repository". Depois de ativar, acrescentar ao `security.yml` um job com
`if: github.event_name == 'pull_request'`, `actions/checkout` e `actions/dependency-review-action` com
`fail-on-severity: high`. Enquanto isso, vulnerabilidade conhecida em dependência é coberta pelo `composer audit`, pelo
`npm audit` (job `audit`) e pelo Dependabot.

**Estado da imagem em 28/10/2026:** 0 vulnerabilidades críticas ou altas com correção publicada (Debian 12.15 e pacotes do
Composer). Existem 296 críticas ou altas **sem correção** no Debian base (por exemplo `bsdutils`); o job as ignora de propósito e
a varredura semanal as traz de volta assim que o Debian publicar a correção.

### Primeira rodada do ZAP no beta (28/10/2026)

Varredura passiva, só das páginas sem login. Triagem de cada aviso:

| Aviso do ZAP | Veredito | O que foi feito |
|---|---|---|
| `X-Content-Type-Options` e HSTS ausentes em assets e fontes | **Real.** A imagem base define os cabeçalhos no `server`, mas o `performance.conf` dela declara `add_header` nos locations de assets e fontes, e o nginx não herda os do `server` num location que declara os próprios | Corrigido: `docker/nginx/static-security-headers.conf` é incluído nesses locations pelo Dockerfile, e o build falha se a inclusão não ocorrer duas vezes |
| CSP e Permissions-Policy ausentes em assets, 404 e rotas do Livewire/Flux | Baixo risco: política de conteúdo só importa em documento HTML. A CSP segue em `report-only` (ver acima) | Nenhuma ação agora |
| Cookie sem `HttpOnly` | **Esperado.** É o `XSRF-TOKEN`, que o Laravel deixa legível pelo JavaScript de propósito; o cookie de sessão tem `HttpOnly` | Nenhuma |
| "Cross-Domain Misconfiguration" nas fontes | Esperado: `Access-Control-Allow-Origin: *` em arquivos de fonte públicos, definido pela imagem base | Nenhuma |
| `Cross-Origin-Embedder-Policy` ausente | Opcional: só serve para isolamento de origem cruzada | Nenhuma |
| Cache-control, conteúdo não armazenável, redirect grande, "Modern Web Application", identificação de sessão e autenticação | Informativos | Nenhuma |

O que passou limpo: nenhum vazamento de cabeçalho de versão ou depuração, nenhuma biblioteca JavaScript vulnerável e nenhum ponto de
XSS refletido nas páginas varridas. O ZAP não faz login, então telas autenticadas (inbox, canais) não foram cobertas.
