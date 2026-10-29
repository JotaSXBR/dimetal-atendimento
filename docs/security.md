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
- **Varreduras no CI** (segredos, revisão de dependências nos PRs, imagem Docker e scan dinâmico contra o beta):
  planejadas em PR próprio.
