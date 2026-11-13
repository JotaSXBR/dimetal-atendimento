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
| Login | Fortify, 5 tentativas por minuto por e-mail e IP e 20 por hora por e-mail, só usuário ativo | cadastro público fechado; quem sabe um e-mail pode travar o login dele por até 1 h |
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
| Login | 5 por minuto por e-mail e IP, e 20 por hora por e-mail de qualquer IP (`SECURITY_LOGIN_PER_HOUR`) | `FortifyServiceProvider`, `config/security.php` |
| Webhook do WAHA | 60 por minuto por IP, **só para assinatura inválida**; assinatura válida nunca é limitada | `AppServiceProvider`, `WebhookSignature`, `config/security.php` |
| Envio de mensagem pelo painel | 30 por minuto por usuário | `Inbox::send`, `config/security.php` |

Todo o tráfego do WAHA chega do mesmo IP interno e vem em rajada (importação, recuperação, presença): um teto por IP dava
429 em evento legítimo, então o webhook só limita a assinatura inválida (o HMAC válido nunca recebe 429; custo: um WAHA
descontrolado não tem freio, a fila absorve). O envio conta
só mensagens: o indicador "digitando" e a gravação de áudio, quando existirem, terão limite próprio e mais folgado
(ver `docs/superpowers/notes/2026-10-28-waha-presenca-e-midia.md`).

## Rota de mídia

`GET /midia/{mensagem}` exige login e a mesma permissão do ticket (`TicketPolicy::view`). O Laravel busca o arquivo no
WAHA com a chave de API e repassa em stream, então o navegador nunca fala com o WAHA nem vê a chave. O nome do arquivo
vem de um evento externo: só `[A-Za-z0-9._-]` sem `..` chega ao WAHA. `Content-Type` sai de uma lista (imagem, áudio,
vídeo, PDF); o resto baixa como `application/octet-stream` com `Content-Disposition: attachment`. Sempre com
`X-Content-Type-Options: nosniff` e `Cache-Control: private`.

## Decisões em aberto

- **2FA obrigatório** para admin e supervisor: o recurso (2FA e passkey) existe e está disponível, mas **não é exigido**
  (decisão do dono, 28/10/2026). Recomendação para antes de subir a produção: exigir de admin e supervisor.
- **Enforce da CSP**: depende do teste do build compatível com CSP descrito acima.

## Auditoria do sistema

Tabela `audit_logs`, só para o admin, em `/auditoria/sistema` (aba "Sistema" da Auditoria; a aba "Tickets" é a trilha de cada
atendimento). Grava, com quem fez, o IP e o que mudou:

| Grupo | Eventos |
|---|---|
| Acesso | login, login recusado (com o e-mail digitado, nunca a senha) e saída |
| Usuários | criar, alterar (campos), mudar o papel (de e para), definir senha (nunca a senha), desativar e reativar |
| Backup | baixar, backup manual, pedir a restauração, voltar ao banco guardado, e o resultado da restauração |
| Configurações | conectar e desconectar o Google (a conta, nunca o token), criar e apagar canal, expurgo manual de anexos |

- Os registros ficam **365 dias** (`app:purge-audit-logs`) porque guardam IP e e-mail digitado.
- Gravar nunca derruba a ação: se a gravação falhar, só o log recebe um aviso (`Auditoria: registro não gravado`) e o login, o
  backup ou a restauração seguem.
- A restauração troca o banco: o **pedido** fica no banco de antes da troca (guardado por 7 dias) e o **resultado** (`restore.succeeded`
  ou `restore.failed`) fica no banco que estiver ativo depois dela. Para reconstituir a história inteira, olhe os dois.
- O teto de 20 logins por hora por e-mail tem um custo: quem sabe o e-mail de alguém consegue travar o login dele por até uma hora.
  Ajuste em `SECURITY_LOGIN_PER_HOUR`.

## Variáveis de produção

Conferir no Coolify antes de subir a produção (o beta é ambiente de desenvolvimento e segue com `LOG_LEVEL=debug`):

| Variável | Valor | Por quê |
|---|---|---|
| `BACKUP_RESTORE_UPLOAD` | `false` | o `pg_restore` executa o arquivo enviado; só restaura backups do próprio bucket |
| `LOG_CHANNEL` / `LOG_LEVEL` | `stderr` / `warning` | o Coolify coleta o log do container; `debug` grava texto de cliente |
| `APP_DEBUG` | `false` | |
| `SESSION_SECURE_COOKIE` | `true` | não depende de o proxy repassar o protocolo |
| `APP_MAINTENANCE_DRIVER` / `APP_MAINTENANCE_STORE` | `cache` / `redis` | app, worker e scheduler compartilham o modo de manutenção da restauração |

## Retenção dos dados guardados

| Dado | Prazo | Rotina |
|---|---|---|
| Evento bruto do webhook (`waha_events`, com o texto e os telefones do payload) | 7 dias | `app:purge-waha-events`, 03:50 |
| Auditoria do sistema (`audit_logs`: IP e e-mail digitado nas falhas de login) | 365 dias | `app:purge-audit-logs`, 03:55 |
| Anexos (arquivo; o texto e os eventos ficam) | 90 dias | `app:purge-media`, 03:30 |
| Alertas do sino | 7 dias | `app:purge-notifications`, 03:45 |

O texto das mensagens e os tickets não têm prazo: decisão de LGPD ainda em aberto.

## Varreduras automáticas

Rodam fora do workflow `tests` de propósito: o `deploy-beta` depende do `tests`, e uma varredura instável não pode travar o
deploy. Ações fixadas por SHA de commit e permissão mínima (`contents: read`).

| Varredura | Quando roda | O que reprova |
|---|---|---|
| Segredos no histórico do git (gitleaks, imagem fixada por digest) | todo PR, push na `main`, semanal | qualquer achado. **Segredo real: parar, rotacionar e avisar. Nunca liberar.** Falso positivo só por impressão digital, em `.gitleaksignore` |
| Imagem Docker (Trivy, alvo `runtime`) | semanal, sob demanda e PR que mexe em `docker/` ou nos lockfiles | CRÍTICA ou ALTA **com correção publicada** (o que não tem correção não é acionável) |
| Scan dinâmico passivo (OWASP ZAP baseline) contra o beta | semanal e sob demanda | nada por enquanto: só relata, até a primeira rodada ser triada |

Rodar na hora: `gh workflow run security` e `gh workflow run security-scan`.

**Revisão de dependências nos PRs:** o job `dependency-review` do `security.yml` (`actions/dependency-review-action`, reprova `high`) depende do Dependency graph, ativado no GitHub (Settings → Advanced Security). Se for desligado, o job passa a reprovar com "Dependency review is not supported on this repository".

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
