# Backup do banco e restauração

O banco (conversas, tickets, contatos, usuários e configuração) é copiado todo dia para o RustFS e pode ser restaurado pela tela.
Os anexos ficam fora: já estão no RustFS e a empresa os puxa por rclone. Desenho:
`docs/superpowers/specs/2026-11-12-backup-banco-design.md` (backup) e `2026-11-12-restauracao-banco-design.md` (restauração).

## O que existe

- **Quando:** todo dia às 02:15 (horário de exibição, `America/Sao_Paulo`), pelo `scheduler`. O comando é `php artisan app:backup-database`
  (com `--manual` para um backup na hora).
- **O quê:** `pg_dump -Fc --compress=zstd:3` (cliente 18), conferido com `pg_restore --list` antes de subir.
- **Onde:** bucket `BACKUP_BUCKET` do RustFS (o `app:prepare-media`, que roda no pós-deploy do app, cria o bucket sozinho). Sem
  `BACKUP_BUCKET` o disco é a pasta local `storage/app/backups` (só dev).
- **Nomes:** `dimetal-AAAA-MM-DD-HHMM-diario|semanal|manual|antes.dump`, no horário de exibição. `antes` é o backup de segurança que a
  restauração faz do estado atual antes de trocar o banco.
- **Retenção:** 7 diários, 2 semanais (cópia do diário de domingo), 3 manuais e 2 "antes". Só depois de um backup novo gravado é que o
  excedente sai.
- **Tela:** Configuração > Backup do banco (só admin): lista, **Baixar**, **Restaurar**, **Fazer backup agora** (a tela atualiza sozinha
  enquanto ele roda), envio de arquivo do computador, bancos guardados e última restauração.
- **Alertas (sino dos admins):** "Backup do banco falhou" a cada falha; "Backup do banco atrasado" quando passa de 26 h sem backup bom
  (no máximo um aviso a cada 24 h; no primeiro deploy conta 26 h da primeira checagem); "Banco restaurado" ao fim de uma restauração.
- **Tempo limite:** o dump é interrompido aos 10 minutos e a conferência aos 2; o `pg_restore` no banco novo também aos 10 (o job todo roda
  na fila `history`, cujo worker tem timeout de 15 minutos). Se o banco crescer a ponto de passar disso, a tela e o sino mostram a causa;
  suba os limites em `DatabaseDumper`/`config/backup.php` junto com o do worker (`docker/worker.sh`).
- **Cliente `pg_dump`/`pg_restore` 18:** em todas as imagens (estágio `runtime` do `docker/Dockerfile`). Se o Postgres subir para a 19,
  trocar `postgresql-client-18` por `postgresql-client-19` lá.

## Restaurar pela tela (o caminho normal)

**Pré-requisitos do ambiente** (a tela recusa e diz o que falta):
- `APP_MAINTENANCE_DRIVER=cache` e `APP_MAINTENANCE_STORE=redis` no app, no worker e no scheduler: a troca põe o sistema em manutenção
  por alguns segundos e só o modo compartilhado pausa os três containers.
- `SESSION_DRIVER=redis` e `CACHE_STORE=redis`: o banco troca no meio, e a página de andamento, o lock e o andamento têm de ficar fora dele.
- O usuário do banco (`DB_USERNAME`) precisa poder criar e renomear bancos (superusuário ou `CREATEDB`) e conectar no banco `postgres`.

**Segurança do arquivo enviado (leia antes de ligar em produção):** o `pg_restore` executa o conteúdo do arquivo com o usuário do banco
(no beta, superusuário). A tela confere os rótulos da TOC e, depois de restaurar no banco novo, o catálogo (nenhuma função, gatilho, visão,
regra ou extensão que o sistema não usa), mas isso **não protege contra um arquivo forjado de propósito** (o corpo de uma entrada pode
trazer SQL que o rótulo não mostra, e uma expressão de `DEFAULT` pode chamar funções do servidor). Por isso: envie só arquivos de origem
confiável (os que o próprio sistema gerou, ou a cópia da empresa), e em produção prefira `BACKUP_RESTORE_UPLOAD=false` (só restaura backups
do bucket) e um usuário de banco que não seja superusuário.

**Passo a passo:**
1. Configuração > Backup do banco. Na linha do backup, **Restaurar**; ou **Enviar arquivo** (um `.dump` deste sistema, até ~100 MB).
2. A tela lê o arquivo e mostra a conferência: data, banco de origem, versões, compressão e tamanho. Arquivo que não for um backup deste
   sistema (com função, gatilho ou outra extensão, por exemplo) é recusado aqui: o `pg_restore` executa o SQL do arquivo.
3. Leia "O que vai acontecer", digite `RESTAURAR` e confirme. A página de **andamento** abre (`/restauracao`) e mostra cada passo:
   conferir o arquivo, backup de segurança, restaurar num banco novo, verificar, trocar, atualizar o esquema, voltar ao ar e recuperar
   as mensagens do WhatsApp.
4. Com o sistema ainda no ar, o arquivo é restaurado num banco novo (`<db>_restaurando_...`) e verificado: tabelas esperadas, pelo menos
   um administrador ativo (senão ninguém entraria) e nenhuma migração desconhecida (backup de versão mais nova que o código é recusado).
5. A **troca** dura segundos, em manutenção: o banco atual vira `<db>_antes_AAAAMMDDHHMM` e o novo assume o nome `<db>`. Se a troca ou as
   migrações falharem, a troca é desfeita sozinha e o banco anterior continua ativo.
6. No fim a página volta para a tela de Backup. Quem estava logado precisa entrar de novo se o usuário não existir no banco restaurado.
7. Depois da troca, as mensagens do WhatsApp posteriores ao backup são pedidas ao WAHA (até 72 h, canais ativos e conectados); o
   resultado chega pelo sino.

**Desfazer:** os bancos guardados aparecem em "Bancos guardados" (nome, data e tamanho). **Voltar para este** faz a mesma troca ao contrário
(o banco ativo vira um novo "guardado"). Eles ficam 7 dias e depois o `app:purge-old-databases` (03:00) os apaga. Cada banco guardado ocupa
o tamanho do banco: confira o disco da VPS.

**Lembretes:**
- **LGPD:** restaurar traz de volta o que foi apagado depois do backup (contatos excluídos, anexos expirados). Reaplique as exclusões
  pedidas desde a data do backup.
- Tickets, notas e configurações feitos depois do backup não voltam (só as mensagens do WhatsApp, pela recuperação).
- Se a tela der erro no meio, a mensagem fica na página de andamento e no log (`Restauração do banco`, com usuário, arquivo e resultado).
- Se o processo morrer no meio (deploy, queda), o estado "em andamento" expira em 1 hora. Um banco `<db>_restaurando_...` que sobrar é
  apagado pela limpeza diária (03:00) depois de 1 dia, e o mesmo vale para arquivos enviados e abandonados.
- Se o sistema ficar preso em manutenção (o desfazer da troca falhou, ou o processo morreu entre entrar e sair): confira qual banco está
  ativo, `php artisan up` em qualquer container do app libera a manutenção e, se o banco ativo tiver ficado com as conexões bloqueadas,
  `ALTER DATABASE <db> WITH ALLOW_CONNECTIONS true;` no banco `postgres`.
- A troca só começa até ~11 minutos depois do início (`backup.restore.cutover_deadline`); depois disso a restauração é cancelada com o banco
  atual intacto. Para bancos que não cabem nisso use o plano B abaixo.
- Não dá para fazer backup (manual ou agendado) durante uma restauração: ele espera.

## Primeiro backup no beta e teste de restauração (para o dono)

1. **Fazer um backup.** Em Configuração > Backup do banco, **Fazer backup agora**. A tela mostra "Backup em andamento" e se atualiza sozinha
   até aparecer "Backup concluído: dimetal-...-manual.dump" (com tamanho e "Último backup bom" preenchido).
2. **Baixar** o arquivo e conferir que veio.
3. **Teste seguro, sem tocar no beta:** siga "Restaurar manualmente num PostgreSQL descartável" abaixo e anote tamanho e tempo.
4. **Teste da restauração pela tela (no beta):** é o beta, não a produção. Faça uma alteração visível (por exemplo, renomeie uma etiqueta),
   **Restaurar** o backup do passo 1, acompanhe a página de andamento e confira que a alteração sumiu. Depois use **Voltar para este** no
   banco guardado e confira que ela voltou.

## Restaurar manualmente num PostgreSQL descartável (plano B e teste sem risco)

Este roteiro **não toca no banco do beta**: o arquivo é baixado pela tela e restaurado num PostgreSQL descartável no seu computador (Docker).

1. **Subir um PostgreSQL descartável** (PowerShell):

   ```powershell
   docker run -d --name pg-restore-teste -e POSTGRES_PASSWORD=teste postgres:18-alpine
   docker cp "$HOME\Downloads\dimetal-AAAA-MM-DD-HHMM-manual.dump" pg-restore-teste:/tmp/backup.dump
   docker exec pg-restore-teste createdb -U postgres dimetal_restaurado
   ```

2. **Restaurar** e medir o tempo:

   ```powershell
   Measure-Command { docker exec pg-restore-teste pg_restore --no-owner -U postgres -d dimetal_restaurado /tmp/backup.dump }
   ```

   Sem mensagem de erro é sucesso.
3. **Conferir** que os dados vieram:

   ```powershell
   docker exec pg-restore-teste psql -U postgres -d dimetal_restaurado -c "select (select count(*) from users) as usuarios, (select count(*) from tickets) as tickets, (select count(*) from messages) as mensagens, (select count(*) from contacts) as contatos"
   ```

4. **Limpar:** `docker rm -f pg-restore-teste`. Anote o tamanho do arquivo e o tempo do passo 2.

**Restaurar na produção sem a tela (plano B de incidente):** em um banco **novo**, nunca por cima do em uso: pare `worker` e `scheduler`,
`createdb` e `pg_restore --no-owner -d <novo> <arquivo>` (cliente 16+), aponte `DB_DATABASE` de app, worker, scheduler e reverb para o banco
novo, rode `php artisan migrate --force` e reinicie.

## Desligar ou trocar

- Parar os backups: tirar `BACKUP_BUCKET` (e reiniciar). O disco vira a pasta local do container, que some no redeploy.
- Trocar o bucket: mudar `BACKUP_BUCKET`; o pós-deploy cria o novo. Os arquivos antigos ficam no bucket velho.
