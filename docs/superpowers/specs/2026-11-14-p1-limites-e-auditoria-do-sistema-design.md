# P1 da auditoria: limites de login e webhook, e auditoria do sistema (design)

Origem: P1 da auditoria de 13/11/2026. Escolhas do dono no chat em 14/11/2026 (abaixo). Não há `docs/superpowers/menores-pendentes.md`.

## Intenção e porquê

1. Um atacante com 100 IPs testa senhas de um admin sem freio: o limite de login é por e-mail **e** IP.
2. O limite do webhook (300/min por IP) vem **antes** do HMAC e todo o WAHA chega do mesmo IP: uma rajada legítima (importação, recuperação) pode ganhar 429 e perder mensagens.
3. Só existe auditoria de ticket. Login, falha de login, criação e mudança de usuários, backup e restauração e configurações sensíveis não deixam rastro (a restauração só vira `Log::notice`).

## Objetivos

- **Login:** além do limite atual, um teto por e-mail, independente do IP.
- **Webhook:** requisição com assinatura válida nunca recebe 429; assinatura inválida leva limite curto por IP.
- **Auditoria do sistema:** tabela própria, gravação dos eventos abaixo e uma tela, só para admin.

## Fora do escopo

Credencial Postgres separada para backup/restauração, LGPD (anonimizar contato, prazo do texto), ensaio da restauração (operacional, do dono), 2FA obrigatório, Sentry.

## Abordagens consideradas (auditoria)

- **Tabela `audit_logs` e gravação explícita nos pontos** (escolhida): cada ponto chama um serviço de uma linha; simples, legível e testável.
- Reaproveitar `ticket_events`: não serve, exige `ticket_id`.
- Só log em arquivo: sem tela e sem consulta; já é o que existe.
- Observers do Eloquent em `User`/`Channel`: gravariam também alterações internas (seeders, comandos) sem dizer *quem* pediu.

## Design

**Limite de login** (`FortifyServiceProvider::configureRateLimiting`): o limitador `login` passa a devolver duas regras: a atual (5 por minuto por e-mail e IP) e `Limit::perHour(config('security.login_per_hour_per_email'))` por e-mail em minúsculas (`config/security.php`, padrão 20).

**Limite do webhook:** a validação da assinatura sai do controller para `App\Services\Waha\WebhookSignature::valid(Request): bool`, usada pelo controller e pelo limitador `webhook`. O limitador devolve `Limit::none()` quando a assinatura é válida e `Limit::perMinute(config('security.webhook_invalid_per_minute'))` por IP quando não é (padrão 60). Saem de `config/security.php` `webhook_per_minute` e `presence_per_minute` (sem uso).

**Auditoria do sistema**
- Tabela `audit_logs`: `id`, `actor_user_id` (nulo, `nullOnDelete`), `actor_label` (nome e e-mail no momento; sobrevive ao usuário apagado e cobre falha de login), `action` (string, 60), `subject` (nulo; texto curto, ex.: "Ana Souza"), `ip` (nulo), `detail` (json nulo, nunca senha nem token), `created_at`. Índices em `created_at` e `(action, created_at)`.
- Enum `App\Enums\AuditAction` (string, com `label()` e `group()`): `auth.login`, `auth.login_failed`, `auth.logout`; `user.created`, `user.updated`, `user.role_changed`, `user.password_set`, `user.deactivated`, `user.activated`; `backup.downloaded`, `backup.manual_requested`, `restore.requested`, `restore.succeeded`, `restore.failed`, `restore.switched`; `google.connected`, `google.disconnected`, `channel.created`, `channel.deleted`, `retention.purge_requested`. Grupos: Acesso, Usuários, Backup, Configurações.
- Serviço `App\Services\Audit\AuditLog::record(AuditAction $action, ?User $actor = null, ?string $subject = null, array $detail = []): void`: lê o IP de `request()` quando há request; **nunca lança** (falha vira `Log::warning`).
- Pontos de gravação: listener dos eventos `Login`, `Failed` e `Logout` do Laravel (falha de login guarda o e-mail digitado e o IP); `Users` (criar, atualizar, mudar papel com antigo e novo, definir senha, ativar/desativar); `BackupDownloadController`; `Backups` (backup manual, pedir restauração, voltar ao banco guardado); `RestoreDatabase` (resultado: sucesso/falha/troca, no mesmo ponto do `log()` atual); `GoogleConnectionController` e `GoogleContacts::disconnect`; `Channels` (criar e apagar); `Retention::purgeNow`.
- Tela: rota `auditoria/sistema` (`can:admin`), componente `App\Livewire\Audit\SystemLog`, com filtros de período (os mesmos de hoje), grupo e usuário, 25 por página, mais novos primeiro. Cada linha: data no fuso de exibição, quem, texto em português e IP. O texto vem de `App\Support\AuditLogText` (rótulo e frase do `detail`, sem JSON). A página atual e a nova passam a ter abas "Tickets" e "Sistema" (parcial compartilhado).
- Retenção: `app:purge-audit-logs`, 365 dias, em lotes, diário às 03:55, `withoutOverlapping(10)`, no padrão de `app:purge-waha-events`; linha na tabela de retenção do `docs/security.md`.

## Decisões minhas (o dono corrige)

- **Teto de 20 logins por hora por e-mail.** Custo se errado: quem sabe o e-mail de alguém pode bloquear o login dele por até 1 hora errando senhas (negação de serviço); o admin não tem como destravar antes. Configurável (`SECURITY_LOGIN_PER_HOUR`).
- **Auditoria falha em silêncio (fail-open).** Custo se errado: um evento deixa de ser gravado sem que ninguém seja avisado além do log; a alternativa (falhar fechado) trancaria o login e a restauração por um problema de auditoria.
- **365 dias de retenção** e IP guardado (dado pessoal; entra na retenção). Custo se errado: o prazo é uma constante.
- **Página separada com abas**, em vez de misturar com os eventos de ticket (formatos diferentes).
- Falha de login guarda o e-mail **como digitado** (pode ser um e-mail que não existe); nunca a senha.
- Tela de Auditoria atual (`Audit\Index`) não muda de comportamento.

## O que fica para o construtor

Nomes exatos de testes e do parcial das abas, redação das frases por ação, e o formato do `detail` de cada ação (sem segredo).
