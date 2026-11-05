# Mídia no RustFS (beta) — design

Data: 2026-11-05. Escopo: só o beta. A produção (`dimetal-main`, `waha`) e os outros projetos do Coolify não são tocados.

## Intenção e porquê

Hoje a mídia recebida vive só no armazenamento do WAHA (o app guarda o nome em `media_ref` e repassa em stream a cada abertura)
e o anexo enviado vive no disco local do container (`local_path`). Nenhum dos dois é um lugar durável e controlado por nós.
O RustFS (S3 compatível, Apache 2.0) passa a ser o lugar da mídia, e é também a base do backup por pull que virá depois.
O WAHA do beta já foi ajustado para guardar arquivos por 7 dias (`WHATSAPP_FILES_LIFETIME=604800`), então o app precisa copiar a
mídia recebida para o bucket dentro desse prazo, na hora.

## Objetivos

1. Mídia nova (recebida e enviada) é gravada num bucket S3 privado do RustFS.
2. `GET /midia/{message}` continua atrás do Gate; serve do bucket e, sem cópia no bucket, cai no WAHA como hoje.
3. A purga de 90 dias apaga do bucket a mídia **recebida e enviada**, registra `media_purged` e a mensagem vira "arquivo expirado".
4. O RustFS e o resto do beta ficam numa rede Docker própria, separada dos demais projetos do Coolify.

## Não objetivos

- Migrar arquivos que já estão no WAHA ou no disco do beta (o dono descartou; dado do beta é de desenvolvimento).
- Backup (pull com rclone, versionamento, Object Lock). Fica para depois.
- Foto do contato, emoji, mídia do bot e logo no bucket. A pasta `internal/` só é reservada.
- Domínio público do RustFS, URL assinada para o navegador, cota por canal.
- Cópia retroativa (comando para recopiar mídia cujo job falhou).

## Antiobjetivo

Mídia recebida no bucket sem prazo de saída (a retenção de 90 dias era do WAHA; agora é do app). Segredo de bucket no repositório.

## Abordagens consideradas

- **A. Um campo, um disco (escolhida).** Disco configurável `media`; `local_path` vira `media_path` (chave no disco) para enviado e
  recebido. Uma regra de leitura, uma de purga.
- B. Campo novo `media_key` só para o bucket, mantendo `local_path`. Dois lugares de armazenamento e a pergunta "qual vale?" em toda
  leitura, purga e teste. Descartada.
- Servir: stream pelo app (escolhido, com `Range` para o Safari) em vez de URL assinada com redirect, que exigiria expor o RustFS.
- Armazenamento: RustFS (escolhido). R2 tem saída gratuita mas 10 GB de disco; MinIO AIStor Free é standalone sob licença do
  fornecedor e a edição comunitária foi arquivada em 25/04/2026. O app só conhece S3 (endpoint e chaves em variáveis), então trocar
  depois é mudar variável.

## Desenho do app

**Disco.** `config/media.php` com `disk => env('MEDIA_DISK', 'local')`; tudo usa `Storage::disk(config('media.disk'))`. Beta:
`MEDIA_DISK=s3` com o disco `s3` existente (`AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT=true`, bucket e chave de IAM só desse
bucket). Testes e dev: `local`. Dependência nova: `league/flysystem-aws-s3-v3`. O disco `s3` tem `throw=false`: toda gravação confere o
retorno e trata falha como erro.

**Banco.** Migration renomeia `messages.local_path` para `media_path`. `media_ref` (nome no WAHA) e `media_purged_at` ficam.

**Chaves no bucket.** `inbound/{channel_id}/AAAA/MM/{uuid}.{ext}` (recebida), `outbound/AAAA/MM/{uuid}.{ext}` (enviada, igual a hoje),
`internal/` reservada e vazia.

**Recebida.** O `ResolveMessageMediaJob`, depois de confirmar o arquivo no WAHA (fluxo atual), lê o arquivo do WAHA em stream e grava
no disco com `writeStream`, grava `media_path` e `media_size`, e só então dispara `MessageStatusChanged`. Se `media_path` já existe e
o objeto existe, não copia de novo (idempotente). Falha de cópia relança a exceção e o job tenta de novo (`tries=4`, backoff
existente). Esgotadas as tentativas, a mensagem fica só com `media_ref` e continua abrindo pelo WAHA enquanto ele guardar (7 dias).

**Enviada.** `SendTicketMedia` grava no disco `media` (hoje `local`); `SendTicketMessage::transmit` lê do mesmo disco. A limpeza em
falha de `record()` continua.

**Leitura.** `MessageMediaController`: Gate primeiro; depois (1) `media_purged_at` preenchido: 404 com a tela mostrando "arquivo
expirado"; (2) `media_path` e objeto existente: stream do disco com `Range` (206/416, `Content-Length`, `Accept-Ranges`), mesmos
cabeçalhos e tipos inline de hoje, `X-Content-Type-Options: nosniff`; (3) sem cópia: stream do WAHA como hoje. Para o S3, o `Range`
é repassado ao `getObject` do cliente do disco; para o disco local, `response()->file`.

**Purga.** `PurgeMediaCommand` passa a olhar `media_path` não nulo e `created_at` com mais de 90 dias, para as duas direções; apaga do
disco `media`, zera `media_path`, preenche `media_purged_at`, cria `TicketEvent media_purged`. Idempotente como hoje. A descrição do
comando e o texto da tela Retenção (`Settings\Retention`) passam a dizer "anexos" sem limitar ao enviado.

## Infraestrutura (Coolify, só o beta)

Estado medido em 05/11: um servidor (o host do Coolify) e uma só rede Docker, `coolify`, compartilhada por todos os projetos,
inclusive `pg-beta`, `redis-beta`, `marichat-db` e a produção. Isso é falha de isolamento e será corrigida junto, a pedido do dono
(dados do beta são descartáveis; o WhatsApp do beta é reconectado pelo dono).

Decisão do dono (05/11): `pg-beta` e `redis-beta` têm dados do sistema e **não são recriados**.

Pela API do Coolify (feito por mim): criar o destino `dimetal-beta` (rede própria); criar `rustfs-beta` (imagem
`rustfs/rustfs:1.0.3`, volume persistente, sem domínio público) nesse destino; criar o bucket privado e a chave de IAM só dele;
atualizar as variáveis do app-beta, worker-beta e scheduler-beta (`MEDIA_DISK`, `AWS_*`); adicionar app, worker, scheduler, reverb
e waha-beta ao destino `dimetal-beta`. Depois de criar a rede, aviso o dono. Pelo dono, na UI: trocar a rede de `pg-beta`,
`redis-beta` e da principal de cada aplicação do beta para `dimetal-beta` (a API só adiciona rede às aplicações e não troca a de
banco), e tirá-las da `coolify`. Só depois disso o isolamento vale. Como o host de banco e Redis é o nome do container, conferir
que os apps ainda os alcançam e, se o nome mudar, atualizar `DB_HOST`/`REDIS_HOST`. Depois: redeploy e conferência.
O RustFS fica sem domínio; o domínio público só entra quando o backup por pull for feito.

## Restrições

- Nunca `waha` (ayu9d64rhirujt27vh5l3irz), `dimetal-main` (nztdbc9d6agpmza31ozhhmo7), MARICHAT nem outros projetos.
- Credenciais do Coolify em `.env.coolify.local`; chaves do bucket só nas variáveis do Coolify, nunca no repositório nem no chat.
- Nunca commitar na `main`; PR só quando o dono pedir.

## Verificação

Testes locais com disco `local`/`Storage::fake`: cópia do job (sucesso, idempotência, falha com retentativa), controller (bucket,
`Range`, fallback ao WAHA, purgada, Gate), envio e purga das duas direções. No beta, com o RustFS real: mídia recebida de verdade
aparece no bucket e abre; foto, áudio (tocar no Safari/Chrome com `Range`), PDF; anexo enviado abre; `media_purged` num registro
forçado a 91 dias; conferir no Chrome a página inteira.

## Decisões minhas (custo se erradas)

- Falha definitiva de cópia não tem recópia automática: a mídia some do WAHA em 7 dias. Custo: perda de arquivo raro; mitigação
  futura é um comando de recópia.
- `Range` no S3 via cliente do disco em vez de URL assinada: mais código no controller, sem expor o RustFS.
- Mensagens antigas (sem `media_path`) seguem pelo WAHA e passam a dar 404 depois dos 7 dias; sem tela nova para isso.
- Recriar pg/redis do beta em vez de manter nos dois: custo é a perda dos dados do beta, já aceita.
