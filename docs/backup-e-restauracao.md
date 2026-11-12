# Backup do banco e restauração

O banco (conversas, tickets, contatos, usuários e configuração) é copiado todo dia para o RustFS. Os anexos ficam fora: já estão no
RustFS e a empresa os puxa por rclone (ver `docs/superpowers/specs/2026-11-12-backup-banco-design.md`).

## O que existe

- **Quando:** todo dia às 02:15 (horário de exibição, `America/Sao_Paulo`), pelo `scheduler`. O comando é `php artisan app:backup-database`
  (com `--manual` para um backup na hora).
- **O quê:** `pg_dump -Fc --compress=zstd:3` (cliente 18), conferido com `pg_restore --list` antes de subir.
- **Onde:** bucket `BACKUP_BUCKET` do RustFS (o `app:prepare-media`, que roda no pós-deploy do app, cria o bucket sozinho). Sem
  `BACKUP_BUCKET` o disco é a pasta local `storage/app/backups` (só dev).
- **Nomes:** `dimetal-AAAA-MM-DD-HHMM-diario|semanal|manual.dump`, no horário de exibição.
- **Retenção:** 7 diários, 2 semanais (cópia do diário de domingo) e 3 manuais. Só depois de um backup novo gravado é que o excedente sai.
- **Tela:** Configuração > Backup do banco (só admin): lista, **Baixar**, **Fazer backup agora**, último sucesso e último erro.
- **Alertas (sino dos admins):** "Backup do banco falhou" a cada falha; "Backup do banco atrasado" quando passa de 26 h sem backup bom
  (no máximo um aviso a cada 24 h).
- **Tempo limite:** o dump é interrompido aos 10 minutos e a conferência aos 2 (o botão **Fazer backup agora** roda na fila `history`, cujo worker tem timeout de 15 minutos). Se o banco crescer a ponto de passar disso, a tela e o sino mostram "pg_dump passou de 10 minutos"; suba o limite em `DatabaseDumper` junto com o do worker (`docker/worker.sh`).
- **Restaurar abre com** `pg_restore` 16 ou mais novo (o arquivo é zstd). O cliente 18 das imagens e o `postgres:18` servem.

## Primeiro backup no beta e teste de restauração (para o dono)

Este roteiro **não toca no banco do beta**: o arquivo é baixado pela tela e restaurado num PostgreSQL descartável no seu computador (Docker).

1. **Fazer um backup.** Em Configuração > Backup do banco, clique em **Fazer backup agora**, espere um minuto e recarregue. Deve aparecer
   `dimetal-...-manual.dump`, com tamanho e "Último backup bom" preenchido. Se aparecer "Última tentativa falhou", copie a mensagem.
2. **Baixar.** Clique em **Baixar** nesse arquivo (vai para a pasta Downloads).
3. **Subir um PostgreSQL descartável** (PowerShell):

   ```powershell
   docker run -d --name pg-restore-teste -e POSTGRES_PASSWORD=teste postgres:18-alpine
   docker cp "$HOME\Downloads\dimetal-AAAA-MM-DD-HHMM-manual.dump" pg-restore-teste:/tmp/backup.dump
   docker exec pg-restore-teste createdb -U postgres dimetal_restaurado
   ```

4. **Restaurar** e medir o tempo:

   ```powershell
   Measure-Command { docker exec pg-restore-teste pg_restore --no-owner -U postgres -d dimetal_restaurado /tmp/backup.dump }
   ```

   Sem mensagem de erro é sucesso.
5. **Conferir** que os dados vieram:

   ```powershell
   docker exec pg-restore-teste psql -U postgres -d dimetal_restaurado -c "select (select count(*) from users) as usuarios, (select count(*) from tickets) as tickets, (select count(*) from messages) as mensagens, (select count(*) from contacts) as contatos"
   ```

   Compare com o beta (o número de usuários e contatos é fácil de ver nas telas; mensagens e tickets devem ser próximos, pois o beta
   continuou recebendo depois do backup).
6. **Limpar:** `docker rm -f pg-restore-teste`.
7. **Anotar** o tamanho do arquivo e o tempo do passo 4 em `docs/superpowers/reports/2026-11-12-backup-banco.md` (ou me passe os números).

## Restaurar de verdade (incidente)

Só com o banco perdido ou corrompido. Faça sempre em um banco **novo**, nunca por cima do que está em uso:

1. Baixe o backup mais recente bom (tela de Backup) ou pegue o arquivo no bucket `backups` do RustFS.
2. Pare `worker` e `scheduler` (e o `app`, se possível) no Coolify, para nada gravar durante a troca.
3. No Postgres do ambiente, crie um banco novo (`createdb`) e restaure com `pg_restore --no-owner -d <novo> <arquivo>` (cliente 16+).
4. Aponte `DB_DATABASE` do app, worker, scheduler e reverb para o banco novo e reinicie; confira o login e a Fila.
5. **LGPD:** a restauração traz de volta o que foi apagado depois do backup (contatos excluídos, anexos expirados). Reaplique as
   exclusões pedidas desde a data do backup.
6. O que chegou no WhatsApp depois do backup volta pela recuperação de mensagens (`app:recover-messages`, 04:15) enquanto o WAHA ainda
   guardar o histórico; tickets, notas e configurações feitos depois do backup não voltam (perda aceita: até 24 h).

## Desligar ou trocar

- Parar os backups: tirar `BACKUP_BUCKET` (e reiniciar). O disco vira a pasta local do container, que some no redeploy.
- Trocar o bucket: mudar `BACKUP_BUCKET`; o pós-deploy cria o novo. Os arquivos antigos ficam no bucket velho.
- Se o Postgres subir para a versão 19, trocar `postgresql-client-18` por `postgresql-client-19` no estágio `dbtools` do `docker/Dockerfile`.
