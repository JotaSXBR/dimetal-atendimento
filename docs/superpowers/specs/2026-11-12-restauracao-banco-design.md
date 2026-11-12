# Restauração do banco pela UI e tela de backup que se atualiza sozinha: design

Data: 2026-11-12. Escopo: só o beta (a produção não existe ainda; nunca tocar a `main`/produção do Coolify). Continua o
[backup diário](2026-11-12-backup-banco-design.md) (PR #107), que deixou "restaurar pela UI" de fora de propósito.

## Intenção e o porquê

O dono quer o **mínimo de atrito** para quem for restaurar o banco (ele, que é o dev, e outras pessoas): escolher um arquivo,
a tela o lê, mostra o que vai acontecer e faz todo o processo sozinha, sem terminal e sem mexer em containers. O padrão de
mercado pesquisado (AWS RDS, Heroku, GitLab, docs do Laravel) é: **restaurar ao lado, conferir, trocar numa janela curta e
guardar o antigo para desfazer**, sempre com backup de segurança antes e confirmação forte.

Junto vai a correção pedida: depois de "Fazer backup agora" a tela hoje não atualiza; precisa se atualizar sozinha.

## Metas

- Restaurar a partir de um backup **da lista do bucket** ou de um **arquivo `.dump` enviado do computador** (limite de 100 MB).
- A tela lê o arquivo antes (data, banco de origem, versões, tamanho), recusa o que não for um dump seguro do sistema e pede
  a confirmação digitada `RESTAURAR`.
- Processo automático, com andamento visível passo a passo numa página que continua no ar durante a manutenção.
- Backup de segurança automático antes; banco anterior guardado 7 dias; botão **Voltar para este** para desfazer.
- Depois da troca, rodar as migrações que faltarem e recuperar do WAHA as mensagens do WhatsApp posteriores ao backup.
- Sem reiniciar containers à mão; sem token do Coolify no app.
- A tela de Backup passa a atualizar sozinha (polling) enquanto um backup manual ou uma restauração está em andamento, e
  mostra "em andamento", "concluído" ou o erro.
- Roteiro de operação e prova real da restauração e do desfazer.

## Não metas

- Restaurar tabelas isoladas, PITR/WAL, Redis, store do WAHA ou mídia.
- Reiniciar containers pela API do Coolify (descartado: daria ao app controle de toda a infraestrutura).
- Pedir a senha do admin de novo (o dono não quis o atrito extra; fica a confirmação digitada).
- Agendar restauração; restaurar em outro servidor/ambiente (isso é o clone do Coolify).

## Anti-metas

- O sistema ficar preso em manutenção ou sem banco ao fim de qualquer falha.
- Restaurar um arquivo que execute código no servidor de banco (o `pg_restore` roda SQL do arquivo como superusuário).
- Perder o banco atual sem caminho de volta.
- Segredo (senha do banco) em log, linha de comando, estado da tela ou mensagem de erro.

## Restrições do ambiente (verificadas em 12/11)

- No beta, o Postgres é o `postgres:18-alpine` com usuário `dimetal`, **superusuário** (é o `POSTGRES_USER`); o app, o worker,
  o scheduler e o reverb usam o mesmo banco `dimetal`. Sessões, cache e filas ficam no **Redis** (sobrevivem à troca).
- O `pg_dump`/`pg_restore` 18 só existe nos estágios `worker` e `scheduler`; o app em si roda o estágio `runtime`
  (`dockerfile_target_build=runtime`). A conferência do arquivo roda no app, então o cliente passa a ir em **todos** os estágios.
- Não há `APP_MAINTENANCE_DRIVER`: o padrão `file` é por container. O modo de manutenção de vários servidores é
  `APP_MAINTENANCE_DRIVER=cache` + `APP_MAINTENANCE_STORE=redis` (docs do Laravel 13); nele as filas não processam e a agenda
  não roda. `bootstrap/app.php` não configura exceções de manutenção.
- O backup diário usa o lock `backup-database`; o worker `history` tem timeout de 900 s e `retry_after` 960 s.
- O Livewire limita upload a 16 MB; um dump pode passar disso: o upload é por rota própria, não pelo Livewire.
- Um dump do sistema só tem estes tipos de entrada (conferido com `pg_restore --list`): `TABLE`, `TABLE DATA`, `SEQUENCE`,
  `SEQUENCE SET`, `SEQUENCE OWNED BY`, `DEFAULT`, `CONSTRAINT`, `FK CONSTRAINT`, `INDEX`, `COMMENT` e `EXTENSION plpgsql`.
- A recuperação de mensagens já existe (`RecoverMessagesJob(channelId, from, to, notifyAlways)` na fila `history`).

## Abordagens consideradas

- **A. Restaurar ao lado e trocar (escolhida pelo dono).** Banco novo, conferência, troca por renomeação em segundos, banco
  antigo guardado. Padrão de mercado (AWS RDS cria instância nova e renomeia; Heroku confirma e recomenda backup antes).
- **B. Restaurar por cima do banco em uso (`--clean`).** Mais simples, mas a parada dura a restauração toda, uma falha deixa o
  banco quebrado e não há volta imediata. Descartada.
- **C. O app comanda o Coolify pela API.** Reinicia containers, mas guarda no app um token que controla toda a infraestrutura
  (inclusive produção) e amarra o sistema à versão do Coolify. Descartada.

## O desenho

**Decidido pelo dono:** abordagem A; origem = lista do bucket **e** envio de arquivo; salvaguardas: confirmação digitada
`RESTAURAR`, backup de segurança antes, banco antigo guardado + botão Desfazer; recuperação automática das mensagens do WAHA.

**Minhas decisões (marcadas para conferir):**

1. **Onde fica:** na página Configuração > Backup do banco. Cada backup ganha **Restaurar**; há também **Enviar arquivo…**.
   Só admin. O envio vai por rota própria (`POST`, multipart, CSRF) para o bucket `backups`, em `restauracao/<ulid>.dump`
   (o catálogo só lista a raiz, então não aparece como backup) e é apagado ao fim da restauração, com sucesso ou falha.
2. **Conferência do arquivo (síncrona, antes de confirmar):** baixa o arquivo para uma pasta temporária, roda
   `pg_restore --list` e mostra data, banco de origem, versão do servidor e do `pg_dump`, compressão e tamanho. **Lista de
   permissão da TOC:** só os tipos listados em "Restrições"; qualquer outro (função, gatilho, regra, visão, extensão que não
   seja `pg_trgm`, publicação...) recusa o arquivo com a mensagem "este arquivo não é um backup deste sistema". Depois de
   restaurar no banco novo, o catálogo também é conferido (nada de função, gatilho, regra, visão ou extensão que o sistema não usa).
   **Limitação conhecida (achada na revisão):** isso pega arquivo errado ou honesto com objetos estranhos, **não um arquivo forjado de
   propósito**: a TOC mostra só rótulos, e o `pg_restore` executa o SQL do corpo de cada entrada (e uma expressão de `DEFAULT` pode chamar
   funções do servidor). O envio de arquivo é só para origem confiável e pode ser desligado com `BACKUP_RESTORE_UPLOAD=false`; endurecer
   de verdade pede um usuário de banco que não seja superusuário.
3. **Pré-requisitos do ambiente (mostrados na conferência; sem eles o botão não habilita):** `APP_MAINTENANCE_DRIVER=cache`
   com store compartilhado; o usuário do banco pode criar/renomear bancos e conectar em `postgres`; cliente `pg_restore`
   com versão igual ou maior que a do dump; nenhum backup ou restauração em andamento (locks `backup-database` e
   `restore-database`).
4. **Confirmação:** caixa de texto; o botão só habilita com `RESTAURAR`. O texto lista o que vai acontecer.
5. **Processo (job `RestoreDatabase` na fila `history`; estado e passos no Redis, não no banco, porque o banco troca):**
   1. conferir arquivo; 2. **backup de segurança** (backup manual do estado atual, tipo novo `antes`, guarda os 2 últimos;
   falhou, aborta); 3. **criar** `<db>_restaurando_<AAAAMMDDHHMM>` e `pg_restore --no-owner --exit-on-error` nele (timeout
   de 600 s); 4. **verificar** o banco novo: as tabelas essenciais existem, há ao menos um admin ativo (senão aborta, para não
   trancar todos para fora) e a tabela `migrations` não tem migração que o código não conhece (backup de versão mais nova);
   5. **trocar** (abaixo); 6. `migrate --force` no banco novo; 7. `queue:restart` e sair da manutenção; 8. **recuperar
   mensagens** do WhatsApp (`RecoverMessagesJob` por canal ativo e conectado, de `max(data do backup, agora − 72 h)` até agora,
   com `notifyAlways`); 9. registrar `backup.last_restore` (quando, arquivo, quem) e avisar os admins no sino.
   Tudo antes do passo 5 acontece com o sistema no ar e uma falha só apaga o banco novo.
6. **A troca (janela curta, em manutenção):** `artisan down` (driver `cache`, página própria), espera as filas
   esvaziarem a tarefa corrente; pela conexão administrativa ao banco `postgres`: `ALLOW_CONNECTIONS false` no banco ativo,
   `pg_terminate_backend` das sessões, `RENAME` do ativo para `<db>_antes_<AAAAMMDDHHMM>`, `RENAME` do novo para `<db>`,
   `ALLOW_CONNECTIONS true`. O app não muda de configuração: continua apontando para `<db>`. Se qualquer passo da troca ou
   o `migrate` falhar, o job **desfaz** (renomeia de volta, libera conexões) e sai da manutenção. `finally` sempre sai da
   manutenção.
7. **Containers:** nenhum é reiniciado à mão. Os workers perdem a conexão e saem (o `worker.sh` já derruba o container e o
   Coolify o sobe de novo) e `queue:restart` pede o mesmo; o scheduler roda um processo por minuto.
8. **Andamento e manutenção:** uma página simples `GET /restauracao` (+ `GET /restauracao/estado` em JSON, sem dado
   sensível) fica **fora** da manutenção (`preventRequestsDuringMaintenance(except: …)`), consulta o estado a cada 2 s e
   volta sozinha para a tela de Backup no fim. Os outros usuários veem uma página 503 própria ("restauração em andamento"),
   que recarrega sozinha. Webhooks do WAHA recebem 503 na janela e o WAHA reenvia; o que se perder volta pelo passo 8.
   Se a sessão do admin não existir no banco restaurado, ele cai no login (a tela avisa).
9. **Desfazer:** a tela lista os bancos guardados (`<db>_antes_*`, pela conexão administrativa, com data e tamanho) e cada um
   tem **Voltar para este**: é a mesma troca (passos 5 a 9 sem criar nem restaurar), e o banco que estava ativo vira o novo
   `_antes_`. Um comando diário (`app:purge-old-databases`) apaga os `_antes_` com mais de 7 dias; só casa o padrão
   `^<db>_antes_\d{12}$` e nunca o banco ativo.
10. **Segurança:** só admin; nomes de banco gerados pelo app e validados por regex (nunca vêm da tela); `Log::notice` de
    cada restauração e desfazer com usuário, arquivo e resultado; senha só em `PGPASSWORD`; mensagens de erro sem senha.
11. **Backup manual que atualiza sozinho (a correção pedida):** `runNow()` marca "em andamento" (chave no Redis, apagada ao
    fim do job, também se falhar) e a tela faz `wire:poll` enquanto isso, até aparecer o arquivo novo ("Backup concluído:
    <nome>") ou o erro. A mesma marca resolve o menor pendente "a tela não mostra em andamento".
12. **Infra:** `postgresql-client-18` sobe para o estágio `runtime` (some o estágio `dbtools`); eu gravo
    `APP_MAINTENANCE_DRIVER=cache` e `APP_MAINTENANCE_STORE=redis` no app, worker e scheduler do beta pelo Coolify (sem isso a
    tela recusa a restauração e diz o que falta); o limite de 100 MB de envio precisa ser conferido contra PHP e nginx da imagem.
13. **Testes:** a lógica com `Process::fake` e disco fake; a troca por renomeação num teste com **Postgres de verdade** usando
    bancos descartáveis próprios (o CI tem Postgres 18 com superusuário); um teste de guarda confere que a lista de permissão
    aceita um dump real do esquema atual; a prova real (restaurar e desfazer na imagem) é manual, no portão 7.

## O que fica para o construtor

- Textos, layout da tela e da página de andamento, intervalo de polling, formato do estado no Redis.
- Detalhes do upload (progresso, limites do nginx/PHP) e da leitura do cabeçalho do `pg_restore --list`.
- Como esperar as filas esvaziarem antes da troca; o formato exato da página 503.
- Se a lista de permissão precisar crescer quando o esquema ganhar visões ou gatilhos (hoje não há).
