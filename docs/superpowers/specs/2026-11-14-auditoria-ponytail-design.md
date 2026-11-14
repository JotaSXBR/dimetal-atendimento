# Auditoria ponytail de 14/11/2026: correções (design)

Branch: `fix/auditoria-ponytail`. Uma fatia só. Numeração da auditoria mantida (1 a 7).

## Intenção e porquê

A auditoria apontou sete pontos. Conferindo o código, só dois pedem mudança de comportamento (1 e 4); um pede só um
comentário (5); quatro já estão resolvidos ou não valem o risco (2, 3, 6, 7). A fatia corrige o que perde dado ou custa caro
em produção e registra por que o resto fica como está, para a próxima auditoria não reabrir.

## Objetivos

- Nenhuma mensagem do WhatsApp descartada em silêncio porque o canal não foi achado na chegada do webhook (item 1).
- Listar backups com uma chamada ao bucket, não uma por arquivo (item 4).
- Deixar escrito no código que o pendente visível a todos é de propósito (item 5).

## Não-objetivos e anti-objetivos

- Não mudar nenhuma regra de permissão (`TicketPolicy`, `scopeVisibleTo`, `readableBy`).
- Não reprocessar eventos antigos já descartados (o `waha_events` guarda 7 dias; se o dono quiser, é outra fatia).
- Não mexer na página de restauração, no contrato `BackupFile` nem na tela Backups.
- Anti-objetivo: cache de permissão entre requisições (troca segurança por pouco ganho, ver item 7).

## Restrições

- As páginas `/restauracao` e `/restauracao/estado` não consultam o banco (`tests/Feature/Restore/MaintenanceExceptionTest.php`).
- Testes de backup usam `Storage::fake('backups')`; o bucket real é RustFS/S3.
- Suíte também com Redis (portão 7): nada de `travel()` para simular expiração.

## Itens

### 1. DEVE CORRIGIR: evento sem canal some sem rastro

**Hoje.** `app/Http/Controllers/Webhooks/WahaWebhookController.php:39` grava `channel_id` só com o que `channelIdFor()`
(`:66-75`) acha na chegada. Se o canal ainda não existe (sessão criada no WAHA antes de o canal ser salvo, corrida no cadastro),
fica nulo. `app/Jobs/HandleWahaEvent.php:40-42` então faz `return` sem log. O WAHA não reenvia webhook aceito (200).

**Abordagens.**
1. No job, se `channel_id` é nulo, procurar de novo por `Channel.waha_session = $event->session`; achando, gravar
   `channel_id` no evento e seguir; não achando, `Log::warning` com o id do evento e a sessão. **Escolhida**: corrige na
   origem do descarte, uma consulta só no caso raro, e os serviços (`IngestMessage`, `ApplyAck::apply`,
   `ApplyMessageChange::find`) que leem `$event->channel` ou `$event->channel_id` passam a ver o valor certo.
2. Devolver erro (5xx) no webhook quando não acha o canal, para o WAHA tentar de novo. Rejeitada: o WAHA não garante novo
   envio, e sessão de teste/desconhecida viraria erro eterno.
3. Só logar. Rejeitada: continua perdendo a mensagem quando o canal aparece segundos depois.

**Detalhe para o construtor.** Usar a coluna `session` do próprio `WahaEvent` (já gravada em `:41`, igual à chave `session`
do payload). Depois de gravar `channel_id`, descartar a relação já carregada (`unsetRelation('channel')` ou `refresh`), porque
`$event->channel` foi lido nulo na condição. Evento `null` (apagado) continua saindo calado.

**Testes (RED esperado)** em `tests/Feature/Jobs/HandleWahaEventTest.php`:
- evento `message` com `channel_id` nulo e `session` de um canal existente: o job grava a mensagem e o `channel_id` do evento.
  RED: zero mensagens.
- mesma coisa com sessão desconhecida: `Log::warning` (via `Log::spy()`/`shouldReceive`) com o id do evento; nada gravado.
  RED: nenhum log.

**Arquivos.** `app/Jobs/HandleWahaEvent.php:36-42`; teste acima.

**Custo se errado.** Se a sessão não bater com `waha_session` (formato diferente), o item não recupera nada, mas o log passa a
mostrar o caso, que hoje é invisível.

### 2. DESCARTADO: tempo real disparado antes do commit

`app/Events/InboxEvent.php:15` já implementa `ShouldDispatchAfterCommit`; `TicketUpdated`, `MessageCreated` e
`MessageStatusChanged` herdam dela. O Laravel segura o evento até o commit (e o descarta no rollback), então
`ClaimTicket.php:37` e os demais pontos dentro de transação (`CloseTicket`, `ReassignTicket`, `TakeoverTicket`,
`TransferTicket`, `LiveEffects`, `SlaClock`, `IngestMessage`) já são seguros. Mover o `dispatch` só por consistência mexeria
em oito arquivos sem mudar nada para o usuário. Nada entra.

### 3. DESCARTADO: teste de autorização da mídia

Já existe: `tests/Feature/Inbox/MessageMediaRouteTest.php:48` (`test_quem_ve_o_ticket_recebe_o_arquivo_sem_vazar_a_chave`),
`:64` (`test_quem_nao_ve_o_ticket_leva_403`) e `:74` (visitante vai ao login). O "no tests found" do índice veio da distância
de chamadas, não da falta de teste.

### 4. DEVERIA: listagem de backup com uma requisição por arquivo

**Hoje.** `app/Services/Backup/BackupCatalog.php:30` lista com `files()` e `:93` chama `size($path)` por arquivo: com 20
backups são 21 requisições ao S3 a cada `all()`, e `find()`, `totalBytes()`, `prune()`, o download e a tela chamam `all()`.

**Abordagens.**
1. Ler a listagem pelo Flysystem: `$this->disk()->getDriver()->listContents('', false)` devolve `FileAttributes` com
   `path()` e `fileSize()` (no S3 vêm do próprio ListObjectsV2; no local e no fake, do `stat`). **Escolhida**: uma chamada,
   sem dependência nova, `BackupFile` igual. Filtrar `isFile()`; se `fileSize()` vier nulo (adaptador que não dá), cair no
   `size($path)` daquele arquivo.
2. Memoizar `all()` por instância. Rejeitada sozinha: reduz chamadas repetidas, mas a primeira continua N+1, e cache em
   objeto de serviço envelhece depois de `prune()` ou de um backup novo.
3. Não fazer. Rejeitada: o custo cresce com a retenção e cai sobre a tela de Backups.

A data continua saindo do nome (contrato atual; `lastModified` não entra).

**Testes.** Os de `tests/Feature/Backup/BackupCatalogTest.php` seguem verdes (comportamento igual). RED novo: um teste que
troca o disco `backups` por um que conta chamadas de `size` (ex.: `Storage::set('backups', ...)` com um
`FilesystemAdapter` que envolve o fake e falha em `size()`), e verifica que `all()` lista os arquivos com o tamanho certo.
RED: o `size()` é chamado.

**Arquivos.** `app/Services/Backup/BackupCatalog.php:26-41` e `:85-94`; `tests/Feature/Backup/BackupCatalogTest.php`.

**Custo se errado.** Se o adaptador S3 instalado não trouxer o tamanho na listagem, o fallback mantém o comportamento de hoje.

### 5. BOM TER: pendente visível a todo atendente (decisão: manter e documentar)

**Decisão do dono:** manter. Entra só um comentário de uma linha em `app/Models/Ticket.php:129` dizendo que é de propósito
(acompanhar a triagem do bot; leitura, não resposta, que a `TicketPolicy` barra).

**Teste.** Já existe e fixa o comportamento: `tests/Feature/Bot/PendingStatusTest.php:79`
(`test_qualquer_usuario_ativo_ve_o_pendente_em_leitura`). Sem teste novo, sem RED (só comentário).

**Custo se errado.** Nenhum para o usuário.

### 6. REDUZIDO A NADA: rótulo do backup na página pública de restauração

**Decisão do dono:** esconder o rótulo do backup de quem não é admin.

**O que o código já faz.** A página e o JSON não mostram rótulo de backup a ninguém. O estado no Redis
(`app/Services/Backup/Restore/RestoreProgress.php:36`) guarda só `status`, `title`, `steps` e `message`. O `title` é fixo
(`app/Livewire/Settings/Backups.php:171` "Restaurando o banco" e `:184` "Voltando para o banco guardado"); as mensagens de
fim e de falha (`RestoreDatabase.php:193-217` e os `RestoreRejected`) não citam nome nem data do arquivo. O nome
(`$run->label`) vai só para `BackupStatus::recordRestore` e para o aviso aos admins, ambos atrás de login.
`tests/Feature/Restore/RestoreStatusTest.php:22` (`test_estado_devolve_os_passos_e_nenhum_dado_sensivel`) já fixa isso.

A decisão do dono já está cumprida; nada muda, e não há o problema de identificar o admin sem banco. Se no futuro alguém
quiser mostrar o rótulo ao admin, a saída sem banco é o parâmetro assinado (`URL::signedRoute`) gerado na tela de Backups.

### 7. DESCARTADO: `TicketPolicy::view` por arquivo de mídia

Cada imagem é uma requisição HTTP separada (`GET /midia/{message}`). Memoizar por requisição não economiza nada; memoizar
entre requisições (cache) deixaria um atendente transferido ou desativado vendo mídia até o cache vencer, que é mudança de
regra de permissão. O custo hoje é de duas consultas por arquivo (`ticket()->firstOrFail()` em
`MessageMediaController.php:34` e o `exists()` em `TicketPolicy.php:13`, com subconsultas no mesmo SQL), pequeno perto do
download do arquivo. Não fazer.

## Decisões do dono

- Item 5: manter o pendente visível a todos e documentar.
- Item 6: esconder o rótulo do backup de quem não é admin (já é assim; ver item 6).

## Decisões que tomei (com o custo se errado)

- Descartar 2, 3 e 7 e reduzir 6: se eu tiver lido errado, a auditoria reabre o ponto; nenhum dado se perde com isso.
- Item 1 grava o `channel_id` achado no evento: se o canal for apagado depois, o evento fica apontando para ele, como os
  eventos normais já ficam.
- Item 4 com fallback para `size()`: código a mais por um caso que talvez não exista, mas evita tamanho zero na tela.

## O que fica para o construtor

- Forma exata do disco contador de chamadas no teste do item 4.
- Texto exato do `Log::warning` do item 1 (incluir id do evento e sessão, nunca o payload).
- Texto do comentário do item 5.
