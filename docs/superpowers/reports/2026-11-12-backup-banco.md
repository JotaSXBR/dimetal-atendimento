# Backup diário do banco: relatório da prova

Data: 2026-11-12. Spec: `docs/superpowers/specs/2026-11-12-backup-banco-design.md`. Plano: `docs/superpowers/plans/2026-11-12-backup-banco.md`.
Roteiro de operação e restauração: `docs/backup-e-restauracao.md`.

## O que foi provado (local)

- **Imagem:** `docker build --target scheduler` e `--target worker` (estágio novo `dbtools`) trazem `pg_dump` e `pg_restore` 18.7 (PGDG).
- **O comando real na imagem real:** o `app:backup-database --manual` rodou dentro da imagem `scheduler` contra um banco de prova
  (30 usuários, 300 tickets, 6.000 mensagens, 300 contatos; 16 MB no Postgres) e gravou `dimetal-...-manual.dump` (0,3 MB) no disco `backups`.
- **Restauração:** `pg_restore --no-owner` do arquivo gerado, na mesma imagem, num banco novo: contagens idênticas
  (30 / 300 / 6.000 / 300), saída 0. Uma segunda restauração do dump feito à mão deu o mesmo resultado.
- **Tempos (amostra pequena, só ordem de grandeza):** dump `zstd:3` 0,17 s; restauração 0,79 s.
- **Compressão:** na amostra sintética `zstd:3` ficou em 298 KB e `gzip:6` em 289 KB (dados de fábrica, quase aleatórios). **Não
  conclui nada para o banco real**: comparar no primeiro dump do beta (ver abaixo).
- **Falha:** com senha errada o comando sai com código 1 e a mensagem `pg_dump falhou (código 1): ... password authentication failed`;
  a senha não aparece na saída. Achado na prova: com o banco fora do ar, gravar o erro e avisar também falhava e escondia o motivo do
  dump; corrigido (`reportFailure`) com teste que falhava antes.

## O que fica com o dono, no beta

1. Depois do merge e do deploy (o bucket nasce no pós-deploy; `BACKUP_BUCKET` já está nas variáveis do app, worker e scheduler do beta).
2. Rodar **Fazer backup agora**, baixar e restaurar num Postgres descartável pelo roteiro (`docs/backup-e-restauracao.md`).
3. Anotar tamanho e tempo reais. Com o tamanho em mãos, decidir: nível do zstd (ou `lz4`/`gzip`) e se vale `--exclude-table-data` para
   `waha_events` (pode ser grande e é reconstruível do WAHA; não decidido).
4. Conferir o disco da VPS: teto de 12 arquivos (7+2+3) vezes o tamanho do dump.

## Não verificado

- O envio ao RustFS real (aqui os testes usam disco fake e a prova usou disco local); a primeira rodada no beta cobre isso.
- Se a chave `AWS_*` do RustFS aceita o segundo bucket (senão o `app:prepare-media` falha com mensagem clara e é preciso uma chave com acesso).
- Tempo e tamanho do banco real.
