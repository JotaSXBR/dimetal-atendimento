# Backup diário do banco: design

Data: 2026-11-12. Escopo: só o beta (a produção não existe ainda e nunca se toca a `main`/produção do Coolify).

## Intenção e o porquê

O banco (tickets, mensagens, contatos, usuários, configuração) é o que não se recupera do WAHA. Hoje não há backup nenhum
(só aparece no design de outubro). É pendência de produção. O dono quer: `pg_dump` diário, **7 diários + 2 semanais**, bem
nomeados, guardados no RustFS, e que o admin possa **baixar pela UI** e **fazer um na hora**. A mídia fica fora: já está no
RustFS e a empresa a puxa por rclone (ver `rustfs-backup-decisao-e-poc`).

## Metas

- Dump diário às 02:15 (antes da purga das 03:30), formato custom (`-Fc --compress=zstd:3`), cliente PostgreSQL 18.
- Retenção: 7 diários, 2 semanais, 3 manuais. Rotação só depois de um backup novo gravado e conferido.
- Tela de Configuração (admin): lista (nome, data, tipo, tamanho, total), botão baixar, botão "Fazer agora", status do último.
- Alerta no sino dos admins quando um backup falha ou quando passa de 26 h sem backup bom.
- Roteiro de restauração (`docs/`) **testado de verdade** em banco descartável, com tempo medido.

## Não metas

- Restaurar pela UI (não haverá botão; restauração é roteiro de operador).
- PITR / arquivamento de WAL (RPO aceito: até 24 h; decisão do dono).
- Backup da mídia, do Redis e do store do WAHA (outras frentes).
- Criptografia própria do arquivo, retenção longa/GFS (a cópia longa é da empresa, por pull, fora do app).
- Agendar horário pela UI (horário fixo no código).

## Anti-metas

- Apagar backup bom por causa de um backup novo que falhou (a rotação nunca roda se o dump falhou).
- Arquivo de backup acessível sem ser admin autenticado (sem URL pública).
- Credencial do banco aparecer em log, na linha de comando (`ps`) ou na UI.

## Restrições do ambiente (verificadas em 12/11)

- Postgres e RustFS do beta são volumes Docker sem cota; o limite é o disco da VPS (a API do Coolify não mostra). O primeiro
  dump real dá o tamanho; a tela mostra tamanho por arquivo e total. Teto: 7+2+3 = 12 arquivos.
- A imagem do app não tem `pg_dump`; o Debian da base traz o cliente 15 e o servidor é 18 (o `pg_dump` recusa servidor de
  versão maior). O cliente 18 vem do repositório PGDG (`postgresql-client-18`).
- `scheduler` e `worker` são estágios do `docker/Dockerfile` (`FROM runtime`); o disco `s3` e o `league/flysystem-aws-s3-v3`
  já existem (`config/filesystems.php`, `Message::mediaDisk()`).
- A página de Auditoria lê só `TicketEvent` (ligado a ticket): não serve para registrar download.
- O padrão de página admin e de job manual já existe: `App\Livewire\Settings\Retention` (`authorize('admin')`,
  `Artisan::queue(...)`), itens em `App\Support\Navigation`, rota em `routes/web.php`, agenda em `routes/console.php`.

## Abordagens consideradas

- **A. Comando próprio no app (escolhida pelo dono).** `app:backup-database` no scheduler, bucket `backups` do RustFS, tela
  lendo o mesmo bucket. Rotação 7+2+3 exata e nomes legíveis; custo: cliente 18 nas imagens e ~150 linhas com testes.
- **B. spatie/laravel-backup.** Rotação e notificação prontas, mas empacota em zip (dificulta `pg_restore` direto), rotação
  não é 7+2 exata e a compatibilidade com Laravel 13 não foi confirmada. Descartada.
- **C. Backup nativo do Coolify.** Sem código de dump, mas "Fazer agora" e lista exigiriam token do Coolify no app e a
  retenção não tem o semanal. Descartada.

## O desenho (como o dono decidiu e o que é decisão minha)

**Decidido pelo dono:** abordagem A; `pg_dump` diário; 7 diários + 2 semanais; nomes claros; baixar pela UI; manual pela UI.

**Minhas decisões (marcadas para conferir):**

1. **Nome:** `dimetal-AAAA-MM-DD-HHMM-diario.dump` (fuso `app.display_timezone`), `...-semanal.dump`, `...-manual.dump`.
2. **Semanal:** na rodada de domingo, depois do diário gravado, copia-se o próprio arquivo (cópia no servidor S3) com sufixo
   `-semanal`. Guardam-se os 2 últimos semanais. **Manual:** guardam-se os 3 últimos, fora da conta dos diários.
3. **Bucket separado** `backups` (disco `backups` em `config/filesystems.php`, mesmas credenciais do RustFS, bucket em
   `BACKUP_BUCKET`), para a purga da mídia nunca tocar nos backups. O bucket é criado sozinho pelo `app:prepare-media` (pós-deploy do app, já existente, estendido a este disco); sem `BACKUP_BUCKET` o disco é local (dev/testes).
4. **Execução:** `Process` com `pg_dump -Fc -h -p -U -d` lendo `config('database.connections.pgsql')`; senha em `PGPASSWORD` no
   ambiente do processo (nunca na linha de comando). Saída para arquivo temporário em `storage/app/tmp` (não em memória),
   conferência com `pg_restore --list` e tamanho > 0, envio em stream (`writeStream`) para o nome final, apaga o temporário.
   Só depois roda a rotação.
5. **Concorrência:** `withoutOverlapping(60)` na agenda e `Cache::lock` no comando, para o manual não rodar junto com o diário.
6. **Manual:** `Artisan::queue('app:backup-database', ['--manual' => true])`, como a Retenção faz com a purga.
7. **Estado/último resultado:** em `settings` (`backup.last_success_at`, `backup.last_error`); a lista vem do bucket (a fonte
   da verdade), não de tabela nova.
8. **Download:** o app repassa o objeto em stream (`readStream`), só admin, `Content-Disposition` com o nome do arquivo; o
   clique registra `Log::notice` com usuário e arquivo (a Auditoria atual é só de ticket).
9. **Alerta:** notificação nova (`BackupFailed` e `BackupStale`) só para admins, no sino existente, sem som próprio.
   `BackupStale` por checagem horária barata (`last_success_at` > 26 h).
10. **Cliente 18:** instalado só nos estágios `worker` e `scheduler` do Dockerfile, com comentário de que sobe junto com o
    servidor (se o Postgres virar 19, subir o pacote). **Compressão zstd** (`--compress=zstd:3`, decisão do dono: preferir o
    moderno); o arquivo só abre com `pg_restore` 16+, o que o cliente 18 já garante e o roteiro de restauração documenta.
    Se o primeiro dump real mostrar tempo ou tamanho ruins, ajustar o nível (ou `lz4`).
11. **Testes:** `Process::fake`/disco fake nos testes do comando e da tela (sem `pg_dump` real no CI, onde o cliente seria
    outra versão); a prova real (dump + `pg_restore` em banco descartável) é manual, no portão 7, e vai ao relatório.

## O que fica para o construtor

- Texto exato da tela e dos alertas; posição do item em Configuração (grupo "Empresa", gate `admin`).
- Detalhes do `Process` (timeout, tratamento de erro, limpeza do temporário) e a lógica de listagem/ordenação pelo nome.
- Se `waha_events` for grande, avaliar `--exclude-table-data` depois do primeiro dump real (não decidido).
- Se o RustFS aceitar a mesma chave em um segundo bucket (a conferir na hora; senão, chave própria só do bucket de backup).
