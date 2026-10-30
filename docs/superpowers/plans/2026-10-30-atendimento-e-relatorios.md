# Atendimento completo, relatórios, auditoria e retenção — plano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (Native, escolhido pelo dono). Steps com `- [ ]`.

**Goal:** Fechar o fluxo do atendente (respostas rápidas, encerrar com motivo e resumo, histórico do contato, assinatura) e dar
ao supervisor/admin relatórios, auditoria e retenção.

**Architecture:** Livewire + Flux como o resto; dados da empresa em `settings` (classe `CompanySettings`); sem dependência nova.

**Tech Stack:** Laravel 13, Livewire 4 + Flux, PostgreSQL 18, PHPUnit 12.

**Specs:** `docs/superpowers/specs/2026-10-30-atendimento-completo-design.md` e `…-relatorios-auditoria-design.md`.

## Global Constraints

- Textos de tela em pt-BR; código em inglês. Só o beta. Sem PR por tarefa (o dono pediu PR e merge no fim). Commits por tarefa
  terminando com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Portão antes de cada commit: `.superpowers/gate.sh` (Pint, PHPStan 6, PHPMD, suíte). PHPMD: complexidade ≤ 10 por método.
- `Edit`/`Write` para PHP; nunca Python com barras invertidas.

## Review Focus

1. Resposta rápida de outro setor não aparece nem é usada por quem não é do setor.
2. Encerrar duas vezes (corrida) não grava dois eventos; resumo/motivo inválidos não encerram.
3. Assinatura nunca duplica no reenvio, não vai em resposta do bot nem em legenda, e o eco `fromMe` casa.
4. `*negrito*` com HTML no texto não injeta marcação (XSS) na bolha.
5. Relatório com zero tickets não divide por zero; atendente não acessa `/relatorios` nem `/auditoria`.

### Task 1: Menu "Atendimento" e dados da empresa

**Files:** Create `app/Settings/CompanySettings.php`, `app/Livewire/Settings/Company.php` + view `resources/views/livewire/settings/company.blade.php`, `tests/Feature/Livewire/CompanyPageTest.php`. Modify `routes/web.php` (admin: `configuracoes/empresa`), `resources/views/layouts/app/sidebar.blade.php` ("Atendimento"; item "Empresa").

**Interfaces:** Produces `CompanySettings::name(): string` (padrão `config('app.name')`), `signatureEnabled(): bool` (padrão true), `signatureTemplate(): string` (padrão `*{{atendente}} @ {{empresa}}:*`), `signaturePosition(): string` (`inicio`|`fim`, padrão `inicio`), `slaMinutes(): int` (padrão 10), `save(array)`.

- [ ] Testes: admin salva e relê; supervisor/atendente recebem 403; validação (nome 1–120, modelo ≤ 300, posição, SLA 1–1440); menu mostra "Atendimento" e não "Fila do setor".
- [ ] Implementar; rodar → verde; commit `feat: menu Atendimento e pagina de dados da empresa`.

### Task 2: Texto no formato do WhatsApp

**Files:** Create `app/Support/WhatsAppText.php`, `tests/Unit/WhatsAppTextTest.php`. Modify `resources/views/livewire/inbox/inbox.blade.php` (corpo da bolha).

**Interfaces:** Produces `WhatsAppText::html(string): HtmlString` (escapa primeiro; `*x*`→strong, `_x_`→em, `~x~`→s, <code>```x```</code>→code).

- [ ] Testes: cada marca; marcas aninhadas simples; `*` solto e `snake_case_nome` não formatam; `<script>` sai escapado; quebra de linha preservada (o CSS já usa `whitespace-pre-wrap`).
- [ ] Implementar e usar na bolha; rodar suíte do inbox → verde; commit `feat: formatacao do WhatsApp nas bolhas`.

### Task 3: Assinatura

**Files:** Create migration `add_sign_messages_to_users`, `app/Services/Inbox/Signature.php`, `tests/Feature/Inbox/SignatureTest.php`. Modify `app/Models/User.php` (`sign_messages`), `app/Livewire/Inbox/Concerns/ComposesMessages.php` (compor o texto e alternar), view do inbox (chave "Assinar"), view da Empresa (editor com botões B/I/S/mono e prévia).

**Interfaces:** Produces `Signature::apply(string $text, User $author, ?Sector $sector): string` (respeita liga geral, liga do usuário, posição; texto vazio devolve vazio). Consumes `CompanySettings`.

- [ ] Testes: início e fim; variáveis; desligada no geral, desligada no usuário; envio pelo painel grava corpo com assinatura e o eco casa; reenvio não assina de novo; legenda de arquivo e resposta do bot sem assinatura; alternar persiste em `users.sign_messages`.
- [ ] Implementar; rodar → verde; commit `feat: assinatura das mensagens do painel`.

### Task 4: Respostas rápidas

**Files:** Create migration `create_quick_replies_table`, `app/Models/QuickReply.php`, `app/Livewire/Settings/QuickReplies.php` + view, `tests/Feature/Livewire/QuickRepliesTest.php`. Modify routes (`can:supervisor`), sidebar, `ComposesMessages.php` (lista e `useQuickReply`), view do inbox.

**Interfaces:** Produces `QuickReply::forUser(User)` scope (setores do usuário ou `sector_id` nulo; supervisor/admin veem todas), `QuickReply::matching(string $draft)`; `useQuickReply(int $id)` no composer.

- [ ] Testes: CRUD com validação (atalho único e no padrão); atendente 403 na configuração; `/pre` lista só as visíveis ao usuário, até 6; rascunho com espaço não lista; `useQuickReply` põe o texto no rascunho e recusa a de outro setor (**Review Focus 1**).
- [ ] Implementar; rodar → verde; commit `feat: respostas rapidas`.

### Task 5: Encerrar com motivo e resumo

**Files:** Create migration `add_close_outcome_and_summary_to_tickets`, `app/Enums/CloseOutcome.php`, `tests/Feature/Inbox/CloseWithSummaryTest.php`. Modify `app/Actions/Inbox/CloseTicket.php`, `app/Models/Ticket.php`, `app/Livewire/Inbox/Inbox.php` (`openPanel('close')`, `close()`), view do inbox.

**Interfaces:** Produces `CloseOutcome` (`RESOLVIDO`, `SEM_RESPOSTA`, `CLIENTE_PEDIU`, com `label()`); `CloseTicket::__invoke(Ticket, User, ClosedReason $reason = MANUAL, ?CloseOutcome $outcome = null, ?string $summary = null)`.

- [ ] Testes: painel exige motivo e resumo (3–500); grava colunas e payload do evento; encerrar duas vezes grava um evento só (**Review Focus 2**); inatividade continua sem motivo; testes antigos de encerramento seguem verdes.
- [ ] Implementar; rodar → verde; commit `feat: encerrar ticket com motivo e resumo`.

### Task 6: Histórico de tickets na ficha do contato

**Files:** Modify `app/Livewire/Contacts/ContactCard.php`, `resources/views/livewire/contacts/contact-card.blade.php`, `tests/Feature/Livewire/ContactCardTest.php`.

- [ ] Testes: lista os 10 mais recentes (protocolo, abertura, setor, estado, motivo e resumo quando encerrado); contato sem tickets mostra "Nenhum atendimento ainda".
- [ ] Implementar; rodar → verde; commit `feat: historico de atendimentos na ficha do contato`.

### Task 7: Relatórios

**Files:** Create `app/Services/Reports/AttendanceReport.php`, `app/Livewire/Reports/Index.php` + view, `tests/Feature/Reports/AttendanceReportTest.php`. Modify routes (`can:supervisor`, `/relatorios`), sidebar.

**Interfaces:** Produces `AttendanceReport::for(CarbonImmutable $from, CarbonImmutable $to, int $slaMinutes): array{opened:int, median_seconds:?int, sla_percent:?float, overdue:int, by_sector:list<array{name,count}>, by_agent:list<array{name,assigned,closed,median_seconds}>}`.

- [ ] Testes com tickets semeados: contagens, mediana, SLA % (dentro/fora), vencidos agora, por setor e por atendente; período vazio sem divisão por zero (**Review Focus 5**); atendente 403; troca de período na tela.
- [ ] Implementar (mediana com `percentile_cont`); rodar → verde; commit `feat: relatorios de atendimento e SLA`.

### Task 8: Auditoria

**Files:** Create `app/Livewire/Audit/Index.php` + view, `tests/Feature/Livewire/AuditPageTest.php`. Modify routes (`can:admin`, `/auditoria`), sidebar.

- [ ] Testes: admin vê eventos paginados do mais novo ao mais velho com protocolo, usuário e detalhe; filtros por período, usuário e tipo; supervisor e atendente 403.
- [ ] Implementar; rodar → verde; commit `feat: auditoria dos eventos de ticket`.

### Task 9: Retenção

**Files:** Create `app/Livewire/Settings/Retention.php` + view, `tests/Feature/Livewire/RetentionPageTest.php`. Modify routes (`can:admin`), sidebar.

- [ ] Testes: mostra prazo, espaço usado (soma de `media_size` dos anexos com cópia local) e quantos passaram do prazo; "Executar expurgo agora" apaga os vencidos; só admin.
- [ ] Implementar; rodar → verde; commit `feat: pagina de retencao com expurgo manual`.

### Task 10: Fechamento

- [ ] Suíte inteira com o ambiente do CI (`QUEUE_CONNECTION=redis CACHE_STORE=redis SESSION_DRIVER=redis`), revisão do branch inteiro, correções, PR e merge (autorizados pelo dono).
