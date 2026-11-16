# Fluxie Inbox: roteiro da instalação por terceiros

Data: 16/11/2026. Status: roteiro aprovado pelo dono no brainstorming. Os projetos 0 e 1 têm design completo em
`2026-11-16-modelo-distribuidora-e-renomeacao-design.md`. Os projetos 2 a 5 passam cada um por brainstorming, spec e plano
próprios antes de qualquer código. Este documento registra as decisões já tomadas para que essas rodadas não as reabram.

## Intenção

Hoje o sistema é "da Dimetal": nasceu como projeto interno e só se instala seguindo `docs/deploy.md`. São cerca de 70 variáveis
de ambiente, apps separados no Coolify que se acham pelo UUID do container e um admin criado no terminal. O dono quer
publicá-lo no GitHub como produto aberto (MIT), com a marca **Fluxie Inbox** (Fluxie é a consultoria de TI dele). Quem quiser
usar instala pelo Coolify, sem mexer em `.env`, e configura o resto na interface, num assistente de primeiro acesso. Mais
adiante, o app entra nos presets do Coolify (instalação com um clique).

Sucesso: uma pessoa com Coolify sobe o compose, abre a URL, digita o código que o Coolify gerou, cria o admin, escolhe o
segmento, lê o QR e já atende. Daí em diante, ela atualiza com um botão.

## Não objetivos

- Instalação fora do Coolify (Docker puro, VPS sem painel, máquina do desenvolvedor) neste momento.
- Outros canais além do WhatsApp. "Inbox" no nome não promete omnichannel.
- Outros segmentos além de Distribuidora e Em branco na primeira versão do assistente.
- Atualização automática sem ação do admin.

## Anti-objetivos

- Qualquer passo que exija editar `.env` para a instalação funcionar, além do que o Coolify preenche sozinho.
- Admin criado por quem chegou primeiro numa URL pública sem prova de posse da instalação.
- Atualização que roda migração sem backup.

## Decisões do dono (16/11/2026)

| Tema | Decisão |
|------|---------|
| Nome | **Fluxie Inbox**. Organização GitHub **`fluxie-tech`**, repositório `fluxie-inbox`, imagens `ghcr.io/fluxie-tech/...` |
| Copyright | `Copyright (c) 2026 Fluxie` (MIT) |
| Nomes técnicos | Tudo de `dimetal` vira `fluxie`: banco, prefixo do backup (aceitando os antigos), composer, imagem base, chaves do navegador |
| Plataforma | Só Coolify, como recurso **Docker Compose com imagens versionadas no GHCR** (app, worker, scheduler, reverb, waha, postgres, redis, rustfs). Substitui a decisão de 05/11 de manter apps separados: o preset do Coolify exige compose com imagens fixas, e no compose os serviços se acham pelo nome. O Dockerfile por papel continua sendo a fonte das imagens |
| WAHA e RustFS | Vêm no compose já ligados, com chaves geradas pelas variáveis mágicas do Coolify (`SERVICE_PASSWORD_*`, `SERVICE_BASE64_*`). Não entram no assistente. O WAHA gratuito tem sessões ilimitadas desde a 2026.6.1 |
| Primeiro acesso | Exige um código gerado pelo Coolify (variável mágica, vista na aba Environment) |
| Assistente | 1) admin + empresa (nome, logo, fuso); 2) segmento: **Distribuidora** (setores, etiquetas e a automação do beta) ou **Em branco**; 3) e-mail e Google, opcionais, que saem do `.env` para o banco; 4) QR do primeiro canal, por último, com a opção de pular |
| Atualização | O app consulta as Releases do GitHub uma vez por dia (ligado por padrão, com opção de desligar; não envia dado da instalação). Em **Configurações > Sistema** o admin cola um token da API do Coolify, guardado criptografado, e ganha o botão **Atualizar**. O botão roda o **backup obrigatório** (se falhar, não atualiza), troca a versão das imagens pela API e reimplanta |
| Ambientes | **Dev local** (servidor local, waha-local, seed). Projeto novo no Coolify com **staging** (número de WhatsApp de teste, CI completo, atualiza a cada merge na `main`) e **production** (vitrine: o mesmo compose dos terceiros, atualiza por Release `vX.Y.Z`). A production não é o uso real da Dimetal |
| Fim do beta | Os projetos `dimetal-beta` e `dimetal-main` do Coolify são apagados depois da prova do projeto 2. Automação, setores e etiquetas já foram exportados (projeto 0) |
| Releases | Merge na `main` publica imagens `edge`, que vão para o staging. A Release `vX.Y.Z` publica imagens com a versão, atualiza a production e faz os terceiros verem o aviso |

## Os projetos, na ordem

| # | Projeto | Depende de | Estado |
|---|---------|-----------|--------|
| 0 | Segmento Distribuidora = automação do beta; o modelo atual vira "Distribuidora completo" | nada | design completo |
| 1 | Renomeação para Fluxie Inbox (organização, repo, imagens, banco, backup, README, LICENSE) | o dono criar `fluxie-tech` e transferir o repo | design completo |
| 2 | Imagens versionadas no GHCR + `compose` para o Coolify + workflows de release; subir staging e production; apagar o beta e a main antigos | 1 | brainstorming a fazer |
| 3 | Primeiro acesso com código + assistente (Flux/Livewire); e-mail e Google passam para o banco | 2 (o código vem do Coolify) | brainstorming a fazer |
| 4 | Aviso de versão nova + botão Atualizar (API do Coolify, backup antes) | 2 (Releases e compose) | brainstorming a fazer |
| 5 | PR do preset em `coollabsio/coolify` (`templates/compose/fluxie-inbox.yaml` + logo + PR de docs) | 2 a 4 provados | futuro |

## O que já se sabe para os projetos 2 a 5

- **Preset do Coolify:** é um compose em `templates/compose/<app>.yaml`, com metadados no topo (`documentation`, `slogan`,
  `category`, `tags`, `logo`, `port`) e imagens com versão fixa (nunca `latest`). O PR vai contra o branch `release/v4.x`
  atual e precisa de um PR de documentação junto. Teste pelo fluxo "Docker Compose Empty".
  Fonte: `coollabsio/coolify-docs`, `content/docs/contribute/service.mdx`.
- **Rede:** dentro de um recurso compose, os serviços se acham pelo nome e pela porta interna. Com "Connect to predefined
  network", nomes podem ganhar prefixo; use `${SERVICE_NAME_X}`.
  Fonte: `content/docs/applications/builds/docker-compose.mdx`.
- **Atualizar pela API:** atualizar a variável de ambiente do serviço (`coolify service env update`, ou o endpoint
  equivalente) e depois "Pull Latest Images & Restart". A simples "Restart" não puxa imagem nova.
  Fonte: `content/docs/services/how-services-work.mdx`.
- **Deploy por webhook:** `POST /api/v1/deploy?uuid=...` com token que tenha permissão `deploy`.
- **Variáveis compartilhadas por ambiente:** `{{environment.X}}`. Clonar um recurso para outro ambiente não copia os dados.
- **Assistente:** os quatro containers leem a configuração do banco. Nenhuma tela grava arquivo. Segredos no banco ficam
  criptografados com a `APP_KEY`, que por isso precisa ser estável. Ela vem de variável mágica do Coolify, e perdê-la quebra os
  segredos e o HMAC do webhook.
- **Projetos de referência** (pistas, ainda a ler na fonte no brainstorming de cada projeto):
  - Uptime Kuma, Chatwoot e Coolify: o primeiro usuário vira admin;
  - Invoice Ninja e Snipe-IT: rota `/setup` com checagem prévia do ambiente;
  - Jenkins e Portainer: código no log ou prazo para o primeiro cadastro;
  - Open WebUI: documenta como risco aceito a janela em que qualquer um vira admin.
- **Pendências que esbarram nestes projetos** (de `pendencias-v1`): tela de SMTP (entra no assistente do projeto 3);
  restauração por upload rodando como superusuário (endurecer antes de expor a production); backup de mídia do RustFS.

## Riscos conhecidos

- **"Fluxie" e "Fluxki":** existe o Fluxki, atendimento brasileiro por WhatsApp e e-mail com SLA, e os nomes se confundem na fala.
  O INPI não foi consultado.
- **Staging e production na mesma VPS:** dobram WAHA, Postgres, Redis e RustFS. O dono confirmou que a VPS aguenta.
- **Token do Coolify no banco:** é um segredo poderoso. Precisa de escopo mínimo (o Coolify tem permissões por token), ficar
  criptografado, aparecer só para admin e entrar na auditoria.
