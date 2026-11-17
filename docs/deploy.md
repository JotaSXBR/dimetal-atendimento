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
5. Abra `https://seu.dominio`. A tela pede o código de instalação: está no Coolify → Service → Environment →
   `SERVICE_PASSWORD_SETUP`. Digite o código, crie o administrador (senha de no mínimo 8 caracteres) e siga os primeiros passos:
   empresa, segmento (Distribuidora ou Em branco) e o primeiro canal, lendo o QR no WhatsApp (ou pulando para depois).
6. Confirme que o canal fica **Conectado** e envie uma mensagem de teste.

Alternativa de emergência, se a tela de instalação não servir: no terminal do serviço `app` (Coolify → Service → `app` →
Terminal), `php artisan app:create-user voce@exemplo.com "Seu Nome" --role=admin`. O comando pergunta a senha; rodar de novo
com o mesmo e-mail atualiza o usuário.

O fuso de exibição vem de `APP_DISPLAY_TIMEZONE` (padrão `America/Sao_Paulo`). Deixe **Connect to Predefined Network**
desligado: cada recurso fica com a rede própria.

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

### Pelo app

Vale para instalação de Release rodando como Service do Coolify (o Coolify injeta `COOLIFY_RESOURCE_UUID` no container). A
0.1.0 ainda não tem o botão: a primeira atualização dela é a manual, abaixo. Versão de desenvolvimento (`sha-…`) não mostra o
botão.

**Token do Coolify.** Uma vez só:

1. Confira que o acesso à API está ligado nas configurações do Coolify e que você é admin ou dono do time.
2. Coolify → **Keys & Tokens** → **API Tokens** → **Create New Token**, com as permissões:

   | Permissão | Para quê |
   |---|---|
   | `read` | ler o Service |
   | `read:sensitive` | ler o compose e as variáveis |
   | `write` | gravar o compose e `FLUXIE_VERSION` |
   | `deploy` | reiniciar o Service |

   Nunca `root` nem `delete`.
3. Copie o token: ele aparece uma vez só.
4. No painel, **Configurações → Sistema → Conexão com o Coolify**: informe a URL do painel do Coolify, cole o token, salve e
   use **Testar conexão**. O teste lê o compose, lê e grava as variáveis (regrava o mesmo valor) e confere a permissão
   `deploy` sem reiniciar nada; cada falha aparece em português.

O token não volta à tela (aparece "token salvo em DD/MM"); **Trocar** grava outro e **Remover** apaga. Ele vale para todos os
recursos daquele time do Coolify: se o Coolify tem outros projetos, crie um time só para o Fluxie.

**Aviso de versão nova.** Uma consulta diária às 07:40 (liga e desliga na mesma tela; **Consultar agora** força) mostra a
versão disponível e **Ver novidades**, e avisa os admins no sino uma vez por versão. Pré-releases só aparecem com
`FLUXIE_UPDATE_PRERELEASE=true` nas variáveis do Service (só para staging e testes).

**Atualizar.** **Atualizar para X.Y.Z** pede a palavra `ATUALIZAR` e mostra os passos (conferindo, backup, aplicando,
reiniciando) e o resultado:

1. Confere o token e as permissões, a imagem da versão nova e o compose da Release.
2. Faz um backup com a versão no nome (`…-v<versão>.dump`).
3. Grava o compose da Release e `FLUXIE_VERSION` no Service e reinicia com pull. O sistema fica fora 1 a 2 minutos.
4. Se o reinício falhar, ou a versão nova não subir em 10 minutos com a anterior ainda rodando, o app devolve sozinho o compose
   e a versão anteriores.
5. Se a versão nova subir mas não confirmar em 60 minutos (por exemplo, migração quebrou), a atualização fica como "falhou":
   siga o [Rollback](#rollback).

Os admins recebem um alerta no sino no fim, em qualquer resultado.

### À mão

1. Faça um backup antes: no painel, **Configuração → Backup do banco → Fazer backup agora** (ver
   [`backup-e-restauracao.md`](backup-e-restauracao.md)).
2. Leia as notas da nova Release.
3. Cole no editor do Service o `docker-compose.yml` da Release nova e, nas variáveis do Service, troque `FLUXIE_VERSION` para
   a versão nova (por exemplo `0.2.0`), os dois juntos. Depois que o botão ou o workflow gravou `FLUXIE_VERSION`, colar só o
   compose não troca a imagem: a variável vence o padrão do arquivo.
4. Use **Pull Latest Images & Restart**. O `app` roda as migrações ao subir.

**Regra das migrações.** Entre o app novo subir e o worker, o scheduler e o Reverb trocarem, há código das duas versões
rodando contra o mesmo banco. Toda migração precisa funcionar com o código da versão anterior: adicionar coluna nullable ou
tabela nova é seguro; remover ou renomear coluna, mudar tipo ou reescrever dados que o código velho ainda grava vai em dois
lançamentos (primeiro o código novo que não usa mais o campo, depois a migração que o remove).

### Rollback

Quando a versão nova não sobe ou a atualização ficou como "falhou":

1. No Coolify, volte o compose da versão anterior (o `docker-compose.yml` da Release dela) e `FLUXIE_VERSION` para a versão
   anterior, e reinicie.
2. No painel, **Configurações → Backup do banco**, restaure o backup feito logo antes de atualizar: pelo botão, é o que traz a
   versão anterior no nome.

Backup de versão mais velha restaura em versão mais nova (a restauração roda as migrações). Backup de versão mais nova em
versão mais velha é recusado ("Atualize o sistema para essa versão antes de restaurar").

## 4. Para nós

### Ambientes

- **staging** (`staging.inbox.fluxie.com.br`): **Application** com build pack Docker Compose, lendo o `docker-compose.yml` da
  `main`. Cada merge verde na `main` publica as tags `edge` e `sha-<sha>` e o workflow `release-image` grava
  `FLUXIE_VERSION=sha-<sha>` no staging e faz o deploy.
- **production** (`inbox.fluxie.com.br`): **Service** com o compose colado da Release (como no passo 2 acima). Ao publicar uma
  Release `vX.Y.Z`, o workflow cria as tags `X.Y.Z` e `X.Y` e anexa à Release o compose com a versão no padrão. O job
  `production` grava o compose e `FLUXIE_VERSION` e reinicia o Service com pull, devolvendo os dois se falhar; pula
  pré-releases. Ele existe só para a primeira atualização da production (0.1.0 → primeira versão com o botão); um PR seguinte
  o tira, e daí em diante a production se atualiza pelo app.

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

Releases só no formato `vX.Y.Z`; outra tag falha na validação. Pré-release publica só a tag completa (sem `X.Y`) e não
atualiza a production.

O CI roda a fumaça de atualização (`docker/smoke/upgrade-smoke.sh`): sobe o compose da Release anterior, cria dados e troca
para o compose e a imagem candidatos. Para ela seguir verde:

- Toda mudança no `docker-compose.yml` precisa funcionar aplicada por cima de uma instalação existente: variável nova com
  padrão ou gerada pelo Coolify (`SERVICE_*`), nada que dependa de recolar o arquivo.
- Toda mudança de dados vai em migração, nunca em comando avulso, para que restaurar um backup antigo também atualize os
  dados.

### Não escalar o worker

O `worker` roda vários processos de fila no mesmo container (um por tipo de trabalho). Não use réplicas dele: com mais de um
processo nas filas de envio e do Google, a ordem das mensagens se perde.

## 5. Diagnóstico

- **Status estranho na tela Canais** → logs do `waha`; ele loga o engine e a sessão.
- **Webhook não chega** → o app responde `401` quando o HMAC não confere e nada é gravado.
- **QR pedido de novo depois de atualizar** → o volume `waha-sessions` foi apagado ou trocado.
- **Fila parada** → confira se o serviço `worker` está saudável.
- **404 ou certificado inválido** → o domínio do serviço `app` não é o real, ou falta um deploy depois de gravá-lo.
