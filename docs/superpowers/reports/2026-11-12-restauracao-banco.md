# Restauração do banco pela tela: relatório da prova

Data: 2026-11-12. Spec: `docs/superpowers/specs/2026-11-12-restauracao-banco-design.md`. Plano: `docs/superpowers/plans/2026-11-12-restauracao-banco.md`.
Roteiro de operação: `docs/backup-e-restauracao.md`.

## O que foi provado (na imagem do branch, com Postgres 18 e Redis de verdade)

Ambiente de prova: imagem `--target worker` construída do branch (cliente 18), Postgres 18 (`pg-test`) e Redis 8, com
`APP_MAINTENANCE_DRIVER=cache` no Redis, e o orquestrador `RestoreDatabase` rodando como no job (um processo que mantém a própria conexão
com o banco que será trocado).

- **Restaurar o backup original:** o backup manual foi restaurado depois de o banco ser alterado (marcador `original` e 1 usuário voltaram,
  no lugar de `alterado-depois-do-backup` e 2 usuários). Todos os 8 passos `done`, manutenção desligada, banco anterior guardado como
  `<db>_antes_...` (conexões bloqueadas), registro da última restauração gravado no banco novo. Tempo: ~4,5 s num banco pequeno.
- **Sessão aberta derrubada:** uma sessão `pg_sleep(120)` aberta no banco ativo foi derrubada pela troca.
- **Desfazer (voltar para o guardado):** o banco guardado voltou a ser o ativo (marcador e usuários do estado anterior) e o que estava ativo
  virou um novo guardado; a lista de guardados mostrou os dois.
- **Arquivo malicioso:** um dump com uma função (`CREATE FUNCTION`) foi recusado já na conferência ("não é um backup deste sistema"): nenhum banco
  novo foi criado, o sistema não entrou em manutenção.
- **Arquivo truncado:** recusado na conferência; nenhum banco `_restaurando_` sobrou.
- **Manutenção compartilhada:** `artisan down` num container pausou o outro (um `artisan serve` em outro container): `/login` e `/inbox`
  deram 503 com a página própria (recarga a cada 10 s), enquanto `/restauracao` e `/restauracao/estado` responderam 200; depois do `up`, tudo
  voltou ao normal.
- **Limites da imagem:** PHP `upload_max_filesize` e `post_max_size` de 100 M e `client_max_body_size` do nginx de 100 M: o limite da tela é
  de 100 MB (arquivo ligeiramente menor que isso, por causa do corpo multipart).

## Defeitos que só a prova real achou (corrigidos com teste que falhava antes)

1. **A troca falhava depois da verificação:** o `verify()` deixava uma conexão aberta no banco novo e o Postgres não renomeia banco em uso.
   Agora a troca derruba as sessões do banco novo também, o `verify()` fecha a sua conexão e a causa real do erro vai para o log.
2. **"Voltar para este" logo depois de restaurar (ou restaurar duas vezes no mesmo minuto) falhava** com `database ... already exists`: o nome
   do banco guardado leva o minuto da troca e colidia. Agora o nome avança um minuto enquanto já existir.

## Não verificado

- A restauração no **beta** de verdade (RustFS real, banco real, o worker `history` do Coolify): fica com o dono, pelo roteiro.
- O `migrate` com migrações pendentes de verdade (o backup de prova já estava na versão do código): coberto só por teste com mocks.
- Um banco grande (tempo da restauração e do dump): a amostra tinha ~16 MB.
- O JS da página de andamento (`resources/js/restore-progress.js`) sem teste automático (não há DOM no `node --test`); foi servido e
  respondeu na prova HTTP, mas o desenho dos passos no navegador deve ser conferido à mão.
