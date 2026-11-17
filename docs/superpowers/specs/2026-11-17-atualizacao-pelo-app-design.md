# Projeto 4: aviso de versão nova e botão Atualizar

Data: 17/11/2026 (reescrita depois dos testes no Coolify do mesmo dia). Branch `feat/atualizacao-pelo-app`. Roteiro:
`2026-11-16-fluxie-inbox-roteiro-design.md` (projeto 4; o 5 é futuro e o roteiro para aqui).

## Intenção

Quem instalou o Fluxie Inbox no Coolify fica sabendo, dentro do app, que saiu uma Release nova, e atualiza com um botão, sem
terminal e sem abrir o painel do Coolify. A atualização não pode falhar por descuido: **tudo o que pode dar errado é conferido
antes de pedir o reinício ao Coolify**, e o caminho é provado antes da entrega (localmente e em recursos descartáveis do Coolify).

Sucesso: numa instalação feita pela Release N-1, o admin vê "versão N disponível", clica em Atualizar, o app confere token,
permissões e imagem, faz o backup, grava no Coolify o compose e a versão da Release N, reinicia, volta sozinho em 1 a 2 minutos na
versão N com os dados intactos, e os admins recebem o aviso. Se algo falhar antes do reinício, nada muda e a tela diz o quê. Se a
troca não acontecer, o app devolve compose e versão ao estado anterior e avisa.

## Como se instala (decisão do dono, 17/11)

| Ambiente | Recurso no Coolify | Como atualiza |
|---|---|---|
| Dev local | — | — |
| Staging (nosso) | Application lendo o `docker-compose.yml` da `main` | CI a cada merge (imagem `sha-…`); não passa pelo botão |
| Production e terceiros | **Service** com o `docker-compose.yml` anexado à Release, colado no Coolify | Botão Atualizar (production também pelo workflow da Release) |

O caminho de um código: dev local → merge na `main` → staging → Release → production e terceiros. Application apontando para o
repositório não é caminho de terceiro (o teste mostrou que um Redeploy comum troca de versão sem backup quando o branch anda).

## Objetivos

- O app sabe a própria versão.
- Consulta diária às Releases do GitHub (ligada por padrão, com opção de desligar), sem enviar dado da instalação.
- Aviso de versão nova aos admins (sino e tela Sistema).
- Tela **Configurações > Sistema** (admin): versão, consulta, conexão com o Coolify e o botão Atualizar.
- Atualização que troca **imagem e compose da mesma Release**, com pré-voo completo, backup obrigatório, estado no banco,
  devolução em caso de falha e fechamento depois do boot.
- A production sobe uma vez pelo workflow (0.1.0 → primeira versão com o botão) e depois se atualiza só pelo botão.
- README e `docs/deploy.md` ensinam a criar o token no Coolify e a usar o botão.

## Não objetivos

- Volta automática para a versão anterior depois que a nova subiu e falhou na migração (recuperação guiada pelo `docs/deploy.md`).
- Atualizar o staging pelo botão.
- Atualizar sozinho, sem ação do admin.
- Instalação como Application apontando para o repositório.
- Preset do Coolify (projeto 5, futuro).

## Anti-objetivos

- Pedir o reinício antes de conferir token, permissões, imagem, compose da Release, ausência de restauração/backup e um backup novo.
- Ficar com compose ou versão trocados no Coolify sem a troca ter acontecido (um reinício qualquer depois atualizaria sem backup).
- A atualização rodar duas vezes.
- Falha silenciosa.
- Token do Coolify em texto puro, em log, na auditoria ou visível depois de salvo.

## Approaches

| | Caminho | Troca |
|---|---|---|
| A | Service, botão troca só a imagem | Compose fica o da instalação; mudança de compose exige recolar à mão |
| **B (escolhido)** | Service, botão troca imagem e compose da Release (`PATCH /services/{uuid}` com `docker_compose_raw`) | Imagem e compose sempre da mesma Release; precisou provar que a troca preserva senhas, domínio e dados (provado) |
| C | Application pelo repositório, branch `stable`, presa num commit | Redeploy comum troca versão sem backup até prender; production teria de ser recriada; fora do padrão dos modelos do Coolify |

O dono escolheu B em 17/11, com a condição de testar tudo antes de entregar. Explicação ao dono:
https://claude.ai/artifact/Ne9GgVpNXXVUV84gFC9zjC

## Fatos provados no Coolify (17/11)

Recursos descartáveis no projeto `fluxie-inbox`, ambiente `staging`, apagados no fim.

1. **Instalação nova por Service** (compose colado): o Coolify gera `SERVICE_PASSWORD_SETUP`; `/login` → `/instalar`; o dono criou o
   admin e concluiu os primeiros passos.
2. **Troca do compose pela API** (`PATCH /services/{uuid}`, `docker_compose_raw` em base64) + `POST /services/{uuid}/restart?latest=true`:
   containers recriados com o compose novo (variável nova visível no `printenv` do container), as 32 variáveis geradas inalteradas,
   domínio igual, dados intactos.
3. **Volta**: `GET /services/{uuid}` devolve `docker_compose_raw` idêntico ao gravado (texto). Dá para guardar e regravar.
4. **Versão em dois lugares**: uma variável `FLUXIE_VERSION` gravada no Service vence o padrão `${FLUXIE_VERSION:-X}` do compose.
5. **Variáveis do Service**: `POST /services/{uuid}/envs` cria (variável existente → 409); `PATCH` atualiza (inexistente → 404).
   Mandar `is_preview: false`. Apagar variável exige a permissão `delete`, que o token do botão não tem: o desenho nunca apaga.
5b. **Variável no bloco `environment` do compose** (`X: ${X:-padrão}`): o Coolify guarda a chave com valor vazio, que não prende (o
   `:-` usa o padrão), e não a preenche quando outro compose muda o padrão. Prende só um valor gravado de fato.
6. **Ensaio da atualização real**: Service nascido do compose da Release 0.1.0 (admin pelo terminal, etiquetas e resposta rápida
   criadas pelo dono) → compose da `main` + `FLUXIE_VERSION=sha-693564e…` + reinício: imagem nova, migrações, instalação marcada como
   concluída (`/login` 200, `/instalar` 404), variáveis antigas inalteradas e `SERVICE_PASSWORD_SETUP` gerada pelo Coolify ao
   receber o compose novo; o dono conferiu depois que as etiquetas e a resposta rápida continuavam lá.
7. **Permissões do token** (documentação do Coolify + teste):
   - só `read`: lê o Service sem o compose; `GET /envs` → 403; gravar → 403 "Missing required permissions: write";
   - `read`, `read:sensitive`, `write` (sem `deploy`): lê compose e variáveis, grava variáveis; qualquer reinício → 403;
   - **prova inofensiva de `deploy`**: `POST /services/<uuid-inexistente>/restart` → 403 sem `deploy`, 404 "Service not found."
     com `deploy`. Nada é reiniciado;
   - `read`, `read:sensitive`, `write`, `deploy`: caminho completo.
8. **Pull que falha**, num Service, logo depois de trocar o compose (17/11, token `full`): com `FLUXIE_VERSION` numa imagem
   inexistente, `restart?latest=true` responde 200 e a pilha inteira fica no ar na versão anterior, saudável, por mais de 3 min, sem
   sinal de erro. O reinício de Service não devolve id de deploy. **Volta testada**: regravar o compose guardado (idêntico) e a
   versão anterior com o token `full` e reiniciar sem pull → saudável na versão certa.
9. **Durante o reinício** o sistema fica fora uns 30 a 40 s (500 e depois 404 do proxy).
10. **Production hoje**: Service com o compose da 0.1.0 colado, sem `FLUXIE_VERSION` gravada; o container recebe
    `COOLIFY_RESOURCE_UUID` e alcança o painel (`https://panel.fluxie.com.br/api/health` → 200). `COOLIFY_URL` no container é a URL
    do app, não do painel.

## Desenho

### Versão da instalação

- O compose passa a entregar `FLUXIE_VERSION: ${FLUXIE_VERSION:-<versão>}` na âncora `x-app-env` (mesma expressão da imagem; o
  workflow da Release já troca o padrão no arquivo anexado). `config('fluxie.version')` lê. Sem a variável (instalação ainda no
  compose da 0.1.0), cai no `FLUXIE_BUILD` gravado na imagem no build (`ENV FLUXIE_BUILD=${VERSION}`).
- Versão semver (`X.Y.Z`) = instalação de Release, com aviso e botão. `sha-…`, `edge` ou `dev` = desenvolvimento: a tela mostra,
  mas não oferece atualizar.
- A 0.1.0 não tem botão: a primeira atualização dela é pelo workflow (production) ou por recolar o compose (terceiros), como o
  `docs/deploy.md` já ensina.

### Consulta diária

- Agendada uma vez por dia, fora do horário do backup, só se ligada (`update.check_enabled`, padrão ligado); botão "Consultar agora".
- `GET https://api.github.com/repos/<config('fluxie.releases_repo')>/releases/latest` (padrão `fluxie-tech/fluxie-inbox`; publicada,
  sem rascunho nem pré-release), sem autenticação, `User-Agent: Fluxie-Inbox`, tempo limite curto, nenhum dado da instalação.
- Guarda: última consulta (quando, deu certo ou o erro), versão mais nova (tag, data, URL das notas, URL do asset
  `docker-compose.yml`).
- Versão nova = semver maior que a atual. Alerta no sino aos admins uma vez por versão. Falha da consulta só aparece na tela.

### Tela Configurações > Sistema

Item novo na navegação, grupo "Empresa", só admin, rota `configuracoes.sistema`:

- versão atual (ou "desenvolvimento" + `sha`), última consulta, liga/desliga e "Consultar agora";
- versão disponível com data e "Ver novidades";
- **Conexão com o Coolify**: URL do painel e token. O token é gravado como `Setting` `secret` e nunca volta à tela ("token salvo em
  DD/MM", Trocar, Remover). O identificador do recurso vem de `COOLIFY_RESOURCE_UUID` (mostrado, não editável; sem ele, a tela diz
  que a instalação não é um Service do Coolify e não oferece o botão). **Testar conexão** roda o pré-voo do Coolify (itens 1 a 3
  abaixo) e mostra cada resultado em português (ex.: "o token não tem a permissão deploy");
- **Atualizar para X.Y.Z**: confirmação que diz o que vai acontecer (conferências, backup, 1 a 2 min fora do ar) e pede a palavra de
  confirmação, como a restauração.

### Atualização

**Estado no banco** (tabela própria `system_updates`): `conferindo` → `backup` → `aplicando` → `reiniciando` → `concluida` |
`falhou` | `revertida`. Guarda quem pediu, versão de origem e destino, o backup feito, o compose e a `FLUXIE_VERSION` anteriores
(o compose criptografado: tem os nomes das variáveis, mas os valores ficam no Coolify), horários e a mensagem. Uma atualização
aberta bloqueia outra, a restauração e o backup manual (e vice-versa).

O job roda na fila `history`, `tries = 1`, timeout que cobre o backup. Cada passo grava o estado antes de agir; um job repetido que
encontra o estado em `aplicando` ou depois não refaz nada.

**Pré-voo (nada muda no Coolify até o fim dele):**

1. O Service `COOLIFY_RESOURCE_UUID` existe e o token lê o compose (`GET /services/{uuid}` com `docker_compose_raw`): prova `read` e
   `read:sensitive`.
2. O token lê as variáveis (`GET /services/{uuid}/envs`) e grava: se `FLUXIE_VERSION` existe, regrava o mesmo valor (`PATCH`); se
   não existe, cria com a versão que está rodando (`POST`), o que não muda a imagem. Assim a volta sempre é regravar um valor,
   nunca apagar.
3. Permissão `deploy`: `POST /services/<uuid-inexistente>/restart` → 404 = tem; 403 = não tem.
4. A imagem `ghcr.io/fluxie-tech/fluxie-inbox:X.Y.Z` existe (manifesto anônimo no GHCR → 200).
5. O `docker-compose.yml` da Release X.Y.Z baixa e é válido: YAML com o serviço `app` e a imagem `…:${FLUXIE_VERSION:-X.Y.Z}` (sem
   lista fixa de serviços: a versão nova pode acrescentar ou tirar algum).
5b. Compose editado à mão: compara o compose atual com o da Release da versão atual; se diferem, a confirmação avisa que as
   edições serão substituídas.
6. Não há restauração, backup ou outra atualização em andamento.
7. Backup novo, do mesmo jeito do manual, esperado até o fim e conferido (`RunBackup` valida com `pg_restore --list`).

**Aplicar:**

8. Guarda o compose atual (`docker_compose_raw`) e o valor atual de `FLUXIE_VERSION` (ou "não existia") no estado.
9. Grava o compose da Release (`PATCH /services/{uuid}`) e `FLUXIE_VERSION=X.Y.Z` (`POST /services/{uuid}/envs`, `is_preview: false`).
10. Grava `reiniciando` e pede `POST /services/{uuid}/restart?latest=true`. Se a chamada falhar, **devolve** o compose e a
    `FLUXIE_VERSION` anteriores (regravando) e grava `revertida` com o motivo. Se der certo, o job termina.

A tela mostra os passos como a página de acompanhamento da restauração; ao perder a conexão, mostra "Atualizando. A página volta
sozinha quando o sistema subir" e tenta de novo até o app responder (404 e 502 do proxy contam como "ainda subindo").

### Fechamento

- **Depois do boot**: só o serviço `app`, uma vez por subida, com trava no banco, fora de request. Versão rodando = destino →
  `concluida`, auditoria e alerta de sucesso aos admins.
- **Vigia no agendador**: a cada minuto, uma atualização em `reiniciando` há mais de 10 minutos com a versão rodando ainda igual à
  origem (o pull falhou: o Coolify manteve os containers antigos) → **devolve** compose e `FLUXIE_VERSION` anteriores pela API,
  grava `revertida`, auditoria e alerta. Se nem a devolução funcionar, `falhou` e alerta com o link da recuperação.
- Se o app novo não sobe (migração quebrou), ninguém fecha: é a recuperação guiada do `docs/deploy.md` (voltar compose e
  `FLUXIE_VERSION` no Coolify e restaurar o backup feito antes). A tela mostra "sem confirmação" se, ao voltar, encontrar o estado
  aberto há mais de 30 minutos.

### Workflow da Release (production)

Ver decisão 5: o job `production` faz a primeira subida (compose + versão, com devolução) e, no PR seguinte à primeira Release com
o botão, sai do workflow. Os segredos `COOLIFY_PRODUCTION_UUID` deixam de ser usados.

### Backup e restauração entre versões (pergunta do dono, 17/11)

- **Backup antigo em versão nova** (já funciona): a restauração roda o passo "Atualizando o esquema" (`Maintenance::migrate`) depois
  da troca, então o banco recebe as migrações da versão instalada, inclusive as que transformam dados.
- **Backup novo em versão velha** (já existe: `SideDatabase::check` recusa migração desconhecida, com teste). Muda só a mensagem,
  que passa a citar a versão do backup quando o nome a trouxer: "Este backup foi feito numa versão mais nova (X). Atualize o sistema
  para essa versão antes de restaurar."
- **O backup guarda a versão** em que foi feito (nos metadados do arquivo ou no nome) e a tela de Backups mostra ("feito na 0.2.0").
  A recuperação guiada do `docs/deploy.md` diz qual backup usar: o que o botão fez logo antes de atualizar.
- **Regra**: toda mudança de dados vai em migração, nunca em comando avulso, para que restaurar um backup antigo também atualize os
  dados.

### Regra do compose

Toda mudança no `docker-compose.yml` precisa funcionar quando aplicada por cima de uma instalação existente: variável nova com
padrão no código ou gerada pelo Coolify (`SERVICE_*`), nada que dependa de recolar o arquivo. A fumaça de atualização prova.

## Testes

1. **Unidade e feature** (CI): cada transição de estado; cada passo do pré-voo falhando (read, read:sensitive, write, deploy,
   imagem, compose inválido, operação em andamento, backup falho); devolução quando o reinício falha (com e sem `FLUXIE_VERSION`
   prévia); job repetido não refaz nada; fechamento depois do boot; vigia devolvendo; consulta ao GitHub (semver, pré-release
   ignorada, falha de rede); token criptografado e fora do HTML. Chamadas ao Coolify e ao GitHub com `Http::fake` usando as respostas
   reais gravadas em 17/11 (ver "Fatos provados").
2. **Fumaça de atualização** (local e CI, junto da fumaça do compose): sobe o compose **da Release anterior** (asset do GitHub) com a
   imagem dela, cria dados (admin pelo comando, setor, etiqueta, resposta rápida), troca para o compose e a imagem candidatos e
   confere migrações, `/up`, `/login` sem cair no `/instalar` e os dados.
3. **Ponta a ponta no Coolify** (descartável, com "sim" do dono para criar e apagar): Service com uma versão que já tem o botão (imagem
   candidata publicada com etiqueta de teste semver de pré-release, ex.: `0.0.0-teste.1` e `0.0.0-teste.2`, e `releases_repo` /
   versão alvo injetáveis para o teste), token `full` colado na tela, botão levando de uma à outra; casos de falha com os tokens
   `read` e `nodeploy` (para no pré-voo, nada muda). Imagem inexistente não passa do pré-voo: o vigia fica coberto por teste de feature
   e pelo fato 8. Etiquetas de teste apagadas no fim.
4. **Conferência visual** da tela Sistema nas resoluções de costume (`conferente-visual`).

## Documentação

- **README**: passo curto "Atualizar pelo app": criar o token no Coolify e colar em Configurações > Sistema.
- **`docs/deploy.md`**: o token vale para todos os recursos daquele time do Coolify (recomendar um time só para o Fluxie, se houver
  outros projetos). Depois que o botão ou o workflow gravou `FLUXIE_VERSION`, colar um compose novo à mão não troca a imagem: o
  caminho manual passa a ser trocar `FLUXIE_VERSION` e o compose juntos. Passo a passo: Keys & Tokens → API Tokens → Create New Token; permissões `read`, `read:sensitive`, `write`,
  `deploy` (e por quê; sem `delete` nem `root`); o token aparece uma vez; o dono do token precisa ser admin ou dono do time; o acesso
  à API precisa estar ligado nas configurações do Coolify; colar no app e **Testar conexão**; trocar e remover; recuperação guiada
  se a versão nova não subir.

## Restrições

- Laravel 13, Livewire 4.4, Flux 2; CSP `enforce`; convenções de tela (`ViewConventionsTest`).
- Token: `Setting` `secret` (criptografado com a `APP_KEY`); nunca em log, auditoria ou HTML.
- Testes com Postgres e na suíte com Redis.

## O que fica para o construtor

- Nomes de classes e colunas; onde roda o "depois do boot" (desde que só no `app`, uma vez por subida, fora de request).
- Prova de escrita do pré-voo (item 2), desde que não mude o comportamento do Service.
- Backup antes do job `production` do workflow.
- Texto das telas, no tom das atuais.

## Decisões minhas depois da revisão do construtor

1. **Estados abertos**: o job grava `reiniciando` **antes** do `POST restart`; se a chamada falhar, devolve e grava `revertida`. O vigia
   cobre todos os abertos: `conferindo` ou `backup` parados há mais de 20 min (job morreu, nada mudou no Coolify) → `falhou`;
   `aplicando` parado há mais de 5 min → devolve compose e versão → `revertida`; `reiniciando` como descrito em Fechamento. Assim a
   trava nunca fica presa.
2. **Pré-release**: o parser aceita semver com sufixo (`0.0.0-teste.1`) como versão de Release. A consulta usa `/releases/latest`;
   com `FLUXIE_UPDATE_PRERELEASE=true` (só para o teste ponta a ponta) usa `/releases` e aceita pré-release. O `release-image.yml`
   já roda em Release publicada, inclusive pré-release: retag e asset do compose valem para ela; o job `production` passa a **pular
   pré-release**.
3. **Backup no job**: o job de atualização chama o `RunBackup` (tipo manual) de forma síncrona, na fila `history` (timeout do job
   900 s, o da fila). Falha ou timeout → `falhou` sem tocar no Coolify. O nome do arquivo que o `RunBackup` grava vai para o estado.
4. **Trava mútua**: `system_updates` é a fonte da atualização. Atualização aberta bloqueia backup manual e restauração (mensagem na
   tela); o backup diário é **pulado** com registro no log enquanto a atualização está em `aplicando` ou `reiniciando` (o job já fez
   um backup minutos antes). A atualização não começa com `RestoreProgress` ou `ManualBackupState` em andamento.
5. **Workflow `production`** (decisão do dono, 17/11): faz só a **primeira** subida da production, da 0.1.0 para a primeira
   versão com o botão (ainda vazia, sem número), gravando compose e versão via `.github/scripts/coolify.sh` (guarda o anterior,
   devolve se o reinício falhar). Dali em diante o job `production` **sai do workflow**: a Release só publica imagem e asset, e a
   production é atualizada pelo botão, como os terceiros.
6. **Fumaça de atualização**: baixa o asset `docker-compose.yml` da Release anterior com `gh` (precisa de rede, como o resto do CI).
   Enquanto a anterior for a 0.1.0, ela é a base, e isso cobre a instalação sem `FLUXIE_VERSION` gravada.

## Pendências do dono

Nenhuma. Production atualizada pelo botão depois da primeira subida (dono, 17/11).
