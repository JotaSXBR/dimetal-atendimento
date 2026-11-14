# Auditoria ponytail: plano de implementação

**Goal:** corrigir os itens 1 e 4 da auditoria de 14/11, documentar o item 5, juntar o branch da animação e abrir um PR único.
**Architecture:** mudanças locais em `HandleWahaEvent` (buscar o canal de novo) e `BackupCatalog` (tamanho vindo da listagem); um comentário em `Ticket`. Sem migration, sem dependência nova.
**Tech Stack:** Laravel 13, Livewire 4.4, Flux 2, PostgreSQL 18, Redis 8, Flysystem (S3/RustFS).
**Spec:** `docs/superpowers/specs/2026-11-14-auditoria-ponytail-design.md` (aprovada).
**Global Constraints:**
- Branch `fix/auditoria-ponytail`; nunca commitar na `main`; só o beta em escopo.
- Execução inline, sem subagentes. Código indexado: `codegraph_explore`/`codegraph node`, nunca Grep/Glob. Editar com `Edit`.
- TDD: ver o RED falhar antes do código. Commits em português com prefixo, terminando com
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Postgres de teste: contêiner `pg-test`, banco `dimetal_test` (receita no `CLAUDE.md` se não estiver de pé).
- Testes afetados: `git status --porcelain | cut -c4- | codegraph affected --stdin -d 2 --quiet | xargs -r php artisan test`.
**Review Focus:** (1) o job não grava `channel_id` errado nem loga payload; (2) `BackupCatalog` devolve o mesmo `BackupFile` de antes, com fallback para `size()`; (3) o merge não perdeu nada dos dois lados.

---

## Task 1: evento sem canal busca o canal de novo (item 1)

**Files:** `app/Jobs/HandleWahaEvent.php:36-42`; `tests/Feature/Jobs/HandleWahaEventTest.php`.
**Interfaces:** `HandleWahaEvent::handle(): void` (igual). Novo método privado `private function channelFor(WahaEvent $event): bool`.

- [ ] Adicionar ao teste (com `use Illuminate\Support\Facades\Log;`):

```php
public function test_evento_sem_canal_acha_o_canal_pela_sessao(): void
{
    $channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
    $event = WahaEvent::create([
        'waha_event_id' => 'evt_sem_canal',
        'channel_id' => null,
        'event' => 'message',
        'session' => 'main',
        'payload' => ['payload' => ['id' => 'false_5516997524950@c.us_SC', 'from' => '5516997524950@c.us', 'fromMe' => false, 'body' => 'Oi', 'timestamp' => 1_793_000_000]],
        'received_at' => now(),
    ]);

    (new HandleWahaEvent($event->id))->handle();

    $this->assertSame($channel->id, $event->fresh()->channel_id);
    $this->assertSame(1, Message::query()->count());
}

public function test_evento_de_sessao_desconhecida_deixa_aviso_no_log(): void
{
    Log::spy();
    $event = WahaEvent::create([
        'waha_event_id' => 'evt_orfao',
        'channel_id' => null,
        'event' => 'message',
        'session' => 'nao-existe',
        'payload' => ['payload' => ['id' => 'false_1@c.us_X', 'from' => '1@c.us', 'fromMe' => false, 'body' => 'Oi', 'timestamp' => 1_793_000_000]],
        'received_at' => now(),
    ]);

    (new HandleWahaEvent($event->id))->handle();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx): bool => ($ctx['waha_event_id'] ?? null) === $event->id && ($ctx['session'] ?? null) === 'nao-existe');
    $this->assertSame(0, Message::query()->count());
}
```

- [ ] Rodar `php artisan test --filter=HandleWahaEventTest`. **Expected:** os dois novos FALHAM (`channel_id` nulo / 0 mensagens; `warning` não recebido).
- [ ] Implementar em `handle()`:

```php
if ($event === null) {
    return;
}

if ($event->channel === null && ! $this->channelFor($event)) {
    Log::warning('Evento do WAHA sem canal: descartado', ['waha_event_id' => $event->id, 'session' => $event->session]);

    return;
}
```

e o método (com `use App\Models\Channel;` e `use Illuminate\Support\Facades\Log;` se faltarem):

```php
/** O canal pode ter sido criado depois da chegada do webhook: tenta de novo pela sessão e grava no evento. */
private function channelFor(WahaEvent $event): bool
{
    $id = $event->session === null ? null : Channel::query()->where('waha_session', $event->session)->value('id');

    if (! is_int($id)) {
        return false;
    }

    $event->update(['channel_id' => $id]);
    $event->unsetRelation('channel');

    return true;
}
```

- [ ] Rodar `php artisan test --filter=HandleWahaEventTest`. **Expected:** todos PASSAM. Rodar os afetados.
- [ ] Commit: `fix: evento do WAHA sem canal procura o canal pela sessão e avisa no log`.

## Task 2: listagem de backup sem uma requisição por arquivo (item 4)

**Files:** `app/Services/Backup/BackupCatalog.php:26-41` e `:85-94`; `tests/Feature/Backup/BackupCatalogTest.php`.
**Interfaces:** `all(): array` (list<BackupFile>, igual). `parse(string $path, ?int $size): ?BackupFile`.

- [ ] Adicionar ao teste (imports `Illuminate\Filesystem\FilesystemAdapter`, `League\Flysystem\Filesystem as Flysystem`, `League\Flysystem\Local\LocalFilesystemAdapter`):

```php
public function test_lista_com_o_tamanho_da_propria_listagem_sem_size_por_arquivo(): void
{
    $root = storage_path('framework/testing/disks/backups-sem-size-'.uniqid());
    $adapter = new LocalFilesystemAdapter($root);
    $disk = new class(new Flysystem($adapter), $adapter, ['root' => $root]) extends FilesystemAdapter
    {
        public function size($path): int
        {
            throw new \LogicException('size() por arquivo não deveria ser chamado');
        }
    };
    Storage::set('backups', $disk);
    $name = $this->stored(BackupKind::Diario, 0, 'abcde');

    $this->assertSame([[$name, 5]], array_map(fn ($f) => [$f->name, $f->size], $this->catalog()->all()));
}
```

(Se o nome do caso do enum não for `Diario`, conferir `app/Services/Backup/BackupKind.php` com `codegraph node`.)

- [ ] Rodar `php artisan test --filter=BackupCatalogTest`. **Expected:** o novo FALHA com `LogicException: size() por arquivo não deveria ser chamado`.
- [ ] Implementar em `all()` (com `use League\Flysystem\FileAttributes;` e `use Illuminate\Filesystem\FilesystemAdapter;`):

```php
$disk = $this->disk();
assert($disk instanceof FilesystemAdapter);

// O ListObjectsV2 do S3 já traz o tamanho: uma requisição para a lista inteira, não uma por arquivo.
foreach ($disk->getDriver()->listContents('', false) as $item) {
    if ($item instanceof FileAttributes && ($file = $this->parse($item->path(), $item->fileSize())) !== null) {
        $files[] = $file;
    }
}
```

e em `parse`: assinatura `private function parse(string $path, ?int $size): ?BackupFile`, trocando `$this->disk()->size($path)` por `$size ?? $this->disk()->size($path)`.

- [ ] Rodar `php artisan test --filter=BackupCatalogTest`. **Expected:** todos PASSAM. Rodar os afetados (inclui download e tela Backups). Rodar `vendor/bin/phpstan analyse --no-progress app/Services/Backup`.
- [ ] Commit: `fix: catálogo de backup lê o tamanho da listagem em vez de uma requisição por arquivo`.

## Task 3: comentário no pendente visível (item 5)

**Files:** `app/Models/Ticket.php:129`.

- [ ] Acima de `->orWhere('status', TicketStatus::PENDENTE->value)` acrescentar:
  `// De propósito: todo atendente acompanha a triagem do bot (só leitura; responder e assumir a TicketPolicy barra).`
- [ ] Sem RED (só comentário); o comportamento já é fixado por `tests/Feature/Bot/PendingStatusTest.php:79`. Rodar `php artisan test --filter=PendingStatusTest`. **Expected:** PASSA.
- [ ] Commit: `docs: explica por que o pendente é visível a todo atendente`.

## Task 4: juntar o branch da animação

- [ ] `git status` limpo (o `docs/prompt-revisao.md` não rastreado fica de fora; não commitar).
- [ ] `git merge --no-ff feat/animacao-paineis-compositor -m "chore: junta a animação dos painéis e do compositor ao branch da auditoria"`.
- [ ] Se houver conflito: resolver mantendo os dois lados, `git add` e `git commit`. **Expected:** `git log --oneline -5` mostra o merge; `docs/superpowers/plans/2026-11-14-animacao-paineis-e-compositor.md` e a spec dela presentes.
- [ ] Rodar `node --test tests/js` e `php artisan test --parallel`. **Expected:** verdes.

## Task 5: portão 7 no conjunto

Ler o código de saída de cada comando (sem `| tail`, ou com `set -o pipefail`):

- [ ] `vendor/bin/pint --parallel --test --cache-file=.pint.cache`
- [ ] `vendor/bin/phpstan analyse --no-progress`
- [ ] `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml`
- [ ] `node --test tests/js`
- [ ] `npm run build`
- [ ] `MSYS_NO_PATHCONV=1 docker run -d --name redis-test -p 6379:6379 redis:8-alpine redis-server --databases 32`, depois
  `CACHE_STORE=redis QUEUE_CONNECTION=redis SESSION_DRIVER=redis REDIS_CLIENT=predis REDIS_HOST=127.0.0.1 REDIS_PORT=6379 php artisan test --parallel`, depois `docker rm -f redis-test`.
- [ ] **Expected:** todos com saída 0. Falha: corrigir com TDD e repetir. Anotar `Gate 7: ok (...)` no ledger.

## Task 6: revisão do branch inteiro

- [ ] Invocar `superpowers:requesting-code-review` inline sobre `git diff main...HEAD`, com o Review Focus acima.
- [ ] Achados por severidade; crítico bloqueia; menores corrigidos antes do PR (TDD, e repetir a Task 5 para o que mudou).
- [ ] Ledger: `Final: self-review` e `Gate 6: ok (...)`.

## Task 7: push e PR (pedido explícito do dono)

- [ ] `git push -u origin fix/auditoria-ponytail`.
- [ ] `gh pr create --base main --title "fix: correções da auditoria e animação dos painéis e do compositor" --body-file <arquivo no scratchpad>`. O corpo, em português e sem nomes pessoais, tem:
  - **Animação dos painéis e do compositor:** resumo a partir do plano `docs/superpowers/plans/2026-11-14-animacao-paineis-e-compositor.md`.
  - **Auditoria:** evento do WAHA sem canal procura o canal pela sessão e avisa no log; catálogo de backup sem uma requisição por arquivo; comentário no pendente visível; itens 2, 3, 6 e 7 descartados com o motivo da spec.
  - Verificação: os comandos do portão 7 e o resultado.
  - Última linha: `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.
- [ ] Não fazer merge. Ledger: `Gate 8: ok (PR aberto, sem merge)`.
