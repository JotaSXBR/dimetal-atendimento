# Instalação no Coolify

O Fluxie Inbox roda como um único Service de Docker Compose no Coolify: app, worker, scheduler, Reverb (tempo real), WAHA
(WhatsApp), PostgreSQL, Redis e RustFS (mídia e backups). Segredos e senhas internas o Coolify gera sozinho.

## 1. Requisitos

- Coolify v4 em um servidor com Docker.
- Um domínio (ou subdomínio) apontando para o servidor, para o HTTPS.

## 2. Instalar

1. No Coolify, crie um projeto e, dentro dele, um recurso do tipo **Service** → **Docker Compose Empty**.
2. Abra a página de **Releases** do repositório (`https://github.com/fluxie-tech/fluxie-inbox/releases`), baixe o
   `docker-compose.yml` anexado à Release mais recente e cole o conteúdo no editor do Service. Use sempre o arquivo da
   Release: ele já traz a versão da imagem daquela Release; o arquivo da `main` aponta para uma versão de desenvolvimento.
3. No serviço `app`, informe o domínio (`https://seu.dominio`). Só o `app` recebe domínio: o tempo real (websocket) sai pelo
   mesmo domínio, em `/app`, pelo nginx do app, sem rota própria no Coolify.
4. Faça o deploy e espere todos os serviços ficarem saudáveis. O `app` roda as migrações ao subir.
5. No terminal do serviço `app` (Coolify → Service → `app` → Terminal), crie o administrador:

   ```
   php artisan app:create-user voce@exemplo.com "Seu Nome" --role=admin
   ```

   O comando pergunta a senha (mínimo de 8 caracteres). Rodar de novo com o mesmo e-mail atualiza o usuário.
6. Entre no painel, abra **Canais**, escolha **Conectar** e leia o QR (ou use o código de pareamento) no WhatsApp. Confirme que
   o canal fica **Conectado** e envie uma mensagem de teste.

Em instalação existente, não troque `APP_NAME`: ele é a base dos prefixos de cache, Redis e do cookie de sessão, e trocar
desloga todos e deixa o cache órfão. Se precisar trocar, fixe antes `CACHE_PREFIX`, `REDIS_PREFIX` e `SESSION_COOKIE` com os
valores atuais.

Os dados ficam em volumes nomeados (banco, Redis, mídia, sessão do WhatsApp). O volume de sessão do WAHA é o que faz o QR
sobreviver a atualizações: não o apague.

### Depois de instalar (opcional): Google Contatos

A credencial é do próprio cliente: crie um cliente OAuth no console do Google e preencha `GOOGLE_CLIENT_ID` e
`GOOGLE_CLIENT_SECRET` nas variáveis do Service, com a URL de retorno `https://seu.dominio/configuracoes/google/callback`.
Uma versão futura vai levar essa configuração para a tela do painel.

## 3. Atualizar

1. Faça um backup antes: no painel, **Configuração → Backup do banco → Fazer backup agora** (ver
   [`backup-e-restauracao.md`](backup-e-restauracao.md)).
2. Leia as notas da nova Release.
3. Nas variáveis do Service, troque `FLUXIE_VERSION` para a versão nova (por exemplo `0.2.0`). Se a variável ainda não
   existir, crie-a.
4. Use **Pull Latest Images & Restart**. O `app` roda as migrações ao subir.

**Regra das migrações.** Entre o app novo subir e o worker, o scheduler e o Reverb trocarem, há código das duas versões
rodando contra o mesmo banco. Toda migração precisa funcionar com o código da versão anterior: adicionar coluna nullable ou
tabela nova é seguro; remover ou renomear coluna, mudar tipo ou reescrever dados que o código velho ainda grava vai em dois
lançamentos (primeiro o código novo que não usa mais o campo, depois a migração que o remove).

**Rollback.** Volte `FLUXIE_VERSION` para a versão anterior e reinicie com pull. Migração destrutiva exige migração de volta
escrita à mão, ou restaurar o backup feito antes de atualizar.

## 4. Para nós

### Ambientes

- **staging** (`staging.inbox.fluxie.com.br`): **Application** com build pack Docker Compose, lendo o `docker-compose.yml` da
  `main`. Cada merge verde na `main` publica as tags `edge` e `sha-<sha>` e o workflow `release-image` grava
  `FLUXIE_VERSION=sha-<sha>` no staging e faz o deploy.
- **production** (`inbox.fluxie.com.br`): **Service** com o compose colado da Release (como no passo 2 acima). Ao publicar uma
  Release `vX.Y.Z`, o workflow cria as tags `X.Y.Z` e `X.Y`, anexa à Release o compose com a versão no padrão, grava
  `FLUXIE_VERSION` e reinicia o Service com pull.

### Segredos do repositório

| Segredo | Para quê |
|---|---|
| `COOLIFY_URL`, `COOLIFY_API_TOKEN` | API do Coolify. O token precisa das permissões deploy, read (sem ela o workflow não espera o deploy terminar) e escrita de variáveis. |
| `COOLIFY_STAGING_UUID` | UUID da Application do staging. Sem ele o workflow publica a imagem e pula o deploy. |
| `COOLIFY_PRODUCTION_UUID` | UUID do Service da production. Sem ele a Release promove a imagem e pula a atualização. |

### Soltar uma versão

1. Confira que o commit a lançar passou pelo `tests` na `main` (a imagem `sha-<sha>` dele precisa existir; sem ela o workflow
   falha).
2. Crie a Release com o `gh`:

   ```
   gh release create vX.Y.Z --target main --generate-notes
   ```

3. Acompanhe o workflow `release-image` no Actions: ele promove a imagem, anexa o compose e atualiza a production.

Releases só no formato `vX.Y.Z`; outra tag falha na validação.

### Não escalar o worker

O `worker` roda vários processos de fila no mesmo container (um por tipo de trabalho). Não use réplicas dele: com mais de um
processo nas filas de envio e do Google, a ordem das mensagens se perde.

## 5. Diagnóstico

- **Status estranho na tela Canais** → logs do `waha`; ele loga o engine e a sessão.
- **Webhook não chega** → o app responde `401` quando o HMAC não confere e nada é gravado.
- **QR pedido de novo depois de atualizar** → o volume `waha-sessions` foi apagado ou trocado.
- **Fila parada** → confira se o serviço `worker` está saudável.
- **404 ou certificado inválido** → o domínio do serviço `app` não é o real, ou falta um deploy depois de gravá-lo.
