# Projeto 2: instalação por compose no Coolify, imagens versionadas e troca do beta

Data: 16/11/2026. Roteiro: `2026-11-16-fluxie-inbox-roteiro-design.md`. Os projetos 0 e 1 estão mesclados (#135, #136).

## Intenção

Hoje o ambiente de teste (dimetal-beta) é feito de 5 apps e 3 bancos criados à mão no Coolify. Cada app faz build do
Dockerfile, e eles se acham pelo UUID do container, porque a API do Coolify não cria apelido de rede. É frágil e ninguém de fora
conseguiria repetir. O dono quer que **qualquer pessoa instale o Fluxie Inbox no Coolify colando um compose**, sem build e quase
sem variáveis, e que o próprio ambiente dele seja montado do mesmo jeito: um **staging** que acompanha a `main` e uma
**production** (vitrine) que só muda com uma Release. Depois disso, `dimetal-beta` e `dimetal-main` são apagados.

Sucesso:
1. A production nasce colando o `compose` da Release `v0.1.0` num "Service" do Coolify, sem nenhuma variável preenchida à mão
   além do domínio. Abre o login, o admin criado pelo terminal entra, o canal conecta pelo QR, e uma mensagem chega e é respondida.
2. Um merge na `main` atualiza o staging sozinho.
3. Uma Release `vX.Y.Z` atualiza a production sozinha.

## Objetivos

1. Uma imagem pública `ghcr.io/fluxie-tech/fluxie-inbox` com as tags:
   - `edge` e `sha-<sha>` a cada push na `main`;
   - `X.Y.Z` e `X.Y` a cada Release `vX.Y.Z`.
2. Um arquivo `docker-compose.yml` na raiz do repositório com app, worker, scheduler, reverb, waha, postgres, redis e rustfs. É o que vai
   na Release e, no futuro, no preset do Coolify.
3. O tempo real (Reverb) passa pelo nginx do app no caminho `/app`: um domínio só.
4. Todos os segredos são gerados pelo Coolify (variáveis mágicas): `APP_KEY`, banco, Redis, RustFS, WAHA, Reverb.
5. Workflows:
   - imagem `edge` + deploy do staging a cada merge na `main` (substitui o `deploy-beta`);
   - imagem versionada + atualização da production a cada Release.
6. Staging (`staging.inbox.fluxie.com.br`, número de WhatsApp de teste) e production (`inbox.fluxie.com.br`), ambos vazios,
   num projeto novo do Coolify, com o admin criado pelo terminal.
7. `dimetal-beta` e `dimetal-main` apagados no fim, com o `deploy-beta` removido.
8. `docs/deploy.md` reescrito como roteiro de instalação no Coolify, para terceiros e para nós.

## Não objetivos

- Assistente de primeiro acesso e e-mail/Google pela tela (projeto 3). Até lá, o admin é criado pelo terminal.
- Aviso de versão nova e botão Atualizar (projeto 4).
- PR do preset no Coolify (projeto 5).
- Migrar dados do beta: os ambientes novos nascem vazios (decisão do dono).
- Instalação fora do Coolify.

## Anti-objetivos

- Um passo da instalação que peça para colar segredo, UUID ou URL interna.
- Duas instâncias do WAHA com o mesmo número conectado ao mesmo tempo, porque os dois bots responderiam o cliente.
- Migração rodando em mais de um container.
- A production atualizar com um merge na `main`.
- Apagar qualquer recurso do Coolify sem o "sim" do dono para aquele recurso.

## Decisões do dono (16/11/2026)

| Tema | Decisão |
|------|---------|
| Domínios | `staging.inbox.fluxie.com.br` e `inbox.fluxie.com.br` (o dono cria o DNS) |
| Primeira Release | `v0.1.0` (a `v1.0.0` fica para quando o preset estiver pronto) |
| Dados | Ambientes novos vazios; admin pelo `php artisan app:create-user` no terminal do Coolify |
| Quem solta versão | Eu, quando o dono pedir: gero o changelog pelos PRs e crio a Release com o `gh` |
| Imagens | **Uma** imagem; o compose escolhe o comando de cada serviço |
| Recursos no Coolify | Staging = Application (build pack Docker Compose) lendo o compose da `main`; production = Service com o compose da Release colado, como um terceiro faria |
| Reverb | Pelo nginx do app em `/app`, sem rota própria no Coolify |
| Troca do beta | Eu conduzo: paro o beta na hora certa, subo o novo, e depois apago `dimetal-beta` e `dimetal-main`, com o "sim" do dono antes de cada parada e cada exclusão |

## Approaches consideradas

- **Imagens.**
  - **Escolhida:** uma imagem (o estágio `runtime` do Dockerfile), com `command` e `healthcheck` por serviço no compose. É um
    build por versão, e o preset fica simples.
  - Descartada: quatro imagens, uma por estágio, como no beta. Quadruplica build e armazenamento sem ganho, e o compose ficaria igual.
- **Recursos no Coolify.**
  - **Escolhida:** staging como Application lendo o compose do repositório, porque acompanha sozinho cada mudança do compose.
    Production como Service colado, porque é exatamente o caminho de quem instala e prova o roteiro e o futuro preset.
  - Descartada: os dois colados. Mais uniforme, mas cada mudança de compose exigiria reaplicar à mão no staging.
- **Reverb.**
  - **Escolhida:** proxy no nginx do app. Um domínio, e nenhuma dependência do "strip prefix" do Coolify, que travou o beta.
  - Descartada: rota própria no Coolify. Não foi confirmado que os Services permitam manter o caminho `/app`.

## Design

### 1. Imagem `fluxie-inbox`

- O `docker/Dockerfile` continua com os estágios. A imagem publicada é o `runtime`, sem `HEALTHCHECK` próprio de worker ou
  scheduler: esses passam a ser declarados no compose. Os estágios `worker`, `scheduler` e `reverb` podem continuar no Dockerfile
  para uso local, ou sair. Fica a critério do builder, desde que o compose não dependa deles.
- `docker/worker.sh` é copiado para a imagem `runtime`, para o compose poder chamá-lo.
- O nginx do `runtime` ganha um `location /app` (e `/apps`, que o Pusher usa na API HTTP) com proxy para `reverb:8080`:
  - `Upgrade`/`Connection` para websocket, `proxy_read_timeout` longo;
  - o host do Reverb vem de variável de ambiente, com padrão `reverb`;
  - o nginx não pode falhar ao subir se o reverb ainda não existir: usar `resolver` do Docker e o upstream numa variável.

  Os cabeçalhos de segurança atuais continuam. Conferir que a CSP já libera `wss://` do mesmo host.
- Labels OCI (`source` = repositório, `version`). Público no GHCR (a organização já permite pacote público).

### 2. `docker-compose.yml` (raiz)

Serviços e o essencial de cada um. Valores exatos ficam com o builder, salvo o que está fixado aqui.

| Serviço | Imagem | Comando / papel | Domínio | Volume | Healthcheck |
|---------|--------|-----------------|---------|--------|-------------|
| `app` | `ghcr.io/fluxie-tech/fluxie-inbox:${FLUXIE_VERSION:-0.1.0}` | padrão da imagem; `AUTORUN_ENABLED=true` (migrate, caches, storage link) | `SERVICE_URL_APP_8080` | `app-storage:/var/www/html/storage/app` | `GET /up` |
| `worker` | mesma | `/usr/local/bin/worker.sh` | — | — | `healthcheck-queue` |
| `scheduler` | mesma | `php artisan schedule:work` | — | — | `healthcheck-schedule` |
| `reverb` | mesma | `php artisan reverb:start --host=0.0.0.0 --port=8080` | — | — | porta 8080 aberta |
| `waha` | `devlikeapro/waha:<versão fixa, ex. 2026.10.1>` com engine GOWS | — | — | `waha-sessions:/app/.sessions`, `waha-media:/app/.media` | `GET /ping` |
| `postgres` | `postgres:18-alpine` | — | — | `pg-data` | `pg_isready` |
| `redis` | `redis:8-alpine` com `requirepass` | — | — | `redis-data` | `redis-cli ping` |
| `rustfs` | `rustfs/rustfs:<versão fixa>` | — | — | `rustfs-data` | endpoint de saúde dele |

- `worker`, `scheduler` e `reverb` usam `AUTORUN_ENABLED=false`. Todos dependem de postgres e redis saudáveis; `app` também de rustfs.
- **Segredos** (gerados pelo Coolify, compartilhados pelo mesmo nome):
  - `APP_KEY=base64:${SERVICE_REALBASE64_32_APPKEY}`;
  - `DB_PASSWORD=${SERVICE_PASSWORD_POSTGRES}`;
  - `REDIS_PASSWORD=${SERVICE_PASSWORD_REDIS}`;
  - `AWS_ACCESS_KEY_ID=${SERVICE_USER_RUSTFS}` e `AWS_SECRET_ACCESS_KEY=${SERVICE_PASSWORD_RUSTFS}`;
  - `WAHA_API_KEY=${SERVICE_PASSWORD_WAHA}` (o mesmo valor no WAHA e no app);
  - `REVERB_APP_ID`, `REVERB_APP_KEY` e `REVERB_APP_SECRET` com `SERVICE_HEX_*`/`SERVICE_PASSWORD_*`.
- **Endereços internos fixos:**
  - `DB_HOST=postgres`, `REDIS_HOST=redis`;
  - `WAHA_URL=http://waha:3000`, `WAHA_WEBHOOK_URL=http://app:8080/webhooks/waha`;
  - `AWS_ENDPOINT=http://rustfs:9000`, `AWS_USE_PATH_STYLE_ENDPOINT=true`;
  - `REVERB_HOST=reverb`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`.
- **Fixos de produção:**
  - `APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=stderr`;
  - `SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `REDIS_CLIENT=phpredis`;
  - `MEDIA_DISK=s3`, `AWS_BUCKET=media`, `BACKUP_BUCKET=backups`;
  - `APP_MAINTENANCE_DRIVER=cache`, `APP_MAINTENANCE_STORE=redis`;
  - `APP_TIMEZONE=UTC`, `APP_DISPLAY_TIMEZONE=America/Sao_Paulo`, `APP_LOCALE=pt_BR`.
- **O que vem do domínio:** `APP_URL=${SERVICE_URL_APP}`. `REVERB_ALLOWED_ORIGINS` sai do host do `APP_URL`, como já faz o `config/reverb.php`.
- **`APP_NAME`:** `"Fluxie Inbox"` fixo no compose. O nome da empresa vai pela tela (Configurações > Empresa); o aviso de não trocar
  `APP_NAME` em instalação existente continua no `deploy.md`.
- **WAHA:** a matriz atual de `docs/superpowers/specs/waha-config.md`, com os valores de `main` (sincronização de histórico
  conservadora), mais o painel e o swagger desligados. Só na rede interna, sem domínio.
- **Buckets:** o pós-deploy do app já roda `app:prepare-media`, que cria os buckets se faltarem. Conferir que ele roda no `AUTORUN`
  ou num `command` de entrada do app.
- **Metadados do preset** no topo, em comentário (`documentation`, `slogan`, `category`, `tags`, `logo`, `port: 8080`). Assim o
  mesmo arquivo serve ao projeto 5.

### 3. Workflows

- **`release-image`** (novo):
  - no push da `main`, depois do `tests` verde: build do `runtime`, push de `edge` e `sha-<sha>`, depois deploy do staging pela API do
    Coolify (`POST /api/v1/deploy?uuid=<staging>`), esperando terminar como o `deploy-beta` faz hoje;
  - na Release publicada `vX.Y.Z`: push de `X.Y.Z` e `X.Y`, depois atualiza a variável `FLUXIE_VERSION` do Service da production e
    reinicia com pull das imagens;
  - anexa o `docker-compose.yml` à Release, com `FLUXIE_VERSION` padrão igual à versão.
- UUIDs do staging e da production em segredos do Actions (`COOLIFY_STAGING_UUID`, `COOLIFY_PRODUCTION_UUID`). O token atual
  precisa da permissão de escrita em variáveis, além de `deploy` e `read`: conferir e, se faltar, o dono gera outro.
- `deploy-beta.yml` sai quando o beta for apagado (passo 6 da troca). O alvo do ZAP em `security-scan.yml` passa para
  `https://staging.inbox.fluxie.com.br`.
- `base-image.yml` não muda.

### 4. Troca do beta (ordem)

1. Branch mesclado e imagem `edge` publicada; a `v0.1.0` é criada (com o OK do dono) e publica `0.1.0`.
2. Dono cria o DNS dos dois domínios. Eu crio o projeto `fluxie-inbox` no Coolify com os ambientes `staging` e `production`.
3. **Staging:** Application (build pack Docker Compose) lendo `docker-compose.yml` da `main`, com `FLUXIE_VERSION=edge`, domínio
   `https://staging.inbox.fluxie.com.br`. Deploy, admin pelo terminal, QR com o **número de teste**.
4. **Production:** Service "Docker Compose Empty" com o compose da Release colado, domínio `https://inbox.fluxie.com.br`. Deploy, admin pelo terminal.
5. **A production usa o mesmo número do beta** (decisão do dono, 16/11). Com o "sim" do dono, **paro o WAHA do beta** (o
   aparelho sai das sessões vinculadas) e só então leio o QR na production. Há uma janela curta sem bot: combinar o horário com o
   dono. Prova: mensagem recebida e respondida, backup manual gerado e listado. O staging usa o número de teste que o dono já tem.
6. Com o "sim" do dono, recurso por recurso: paro e apago `dimetal-beta` e `dimetal-main` no Coolify, desativo e removo o
   `deploy-beta`, e confiro que não sobrou rede nem volume órfão.
7. `docs/deploy.md` e as memórias passam a descrever os recursos novos.

## Restrições

- Coolify v4 da VPS atual. Variáveis mágicas e "Docker Compose Empty" confirmados na documentação oficial.
- Imagens de terceiros com versão fixa (regra do preset; nada de `latest`).
- O worker continua com **um** processo em `outbound` e em `google` (ordem das mensagens). Sem réplicas.
- O beta segue no ar e recebendo deploy até o passo 6.

## Prova

- **Local:** `docker compose -f docker-compose.yml config` válido. Subir o compose localmente (com valores no lugar das variáveis
  mágicas) e ver o login, o `/up`, o websocket do Reverb conectando em `/app` e os healthchecks verdes.
- **Portão 7** completo no branch.
- **Staging:** deploy automático por merge, websocket conectado, QR do número de teste, mensagem ida e volta, backup manual.
- **Production:** o mesmo, instalada colando o compose da `v0.1.0` sem nenhuma variável manual além do domínio.

## Decisões minhas depois da revisão do design (16/11)

- **Staging com `sha-<sha>`, não `edge`:** o workflow grava `FLUXIE_VERSION=sha-<sha>` no staging antes do deploy, e isso força
  recriar o container sem depender de pull de tag móvel. A tag `edge` continua publicada, para quem quiser acompanhar a `main`.
- **Production:** a variável `FLUXIE_VERSION` gravada pelo workflow vence o padrão do compose; o workflow a cria se não existir e
  depois reinicia o Service com pull. Antes de depender disso, conferir que o token tem permissão para alterar variáveis de Service e
  reiniciar com pull. Se faltar, o dono gera um token novo.
- **Buckets:** `app:prepare-media` roda no início do container do app (idempotente, esperando o RustFS responder), e o `app`
  depende do `rustfs` saudável. O pós-deploy do Coolify não existe num compose colado.
- **Nomes internos:** antes do staging, valido num recurso descartável que `postgres`, `redis`, `waha`, `rustfs` e `reverb` resolvem
  pelo nome tanto em Application (build pack compose) quanto em Service. Se não resolverem, ajusto aliases no compose.

## Deixado para o builder

- Nomes exatos das variáveis mágicas além das fixadas; parâmetros dos healthchecks; versão fixa do RustFS e do WAHA (a mais nova
  estável e compatível com o que o beta usa).
- Se os estágios `worker`, `scheduler` e `reverb` saem do Dockerfile.
- Como o workflow espera o deploy (reaproveitar a função do `deploy-beta`).
