# Dependabot: versões fixas e grupos (design curto)

## Intenção
Manter o stack atualizado sem depender de lembrar: o Dependabot precisa enxergar PHP, Node e as imagens de serviço, e uma
versão maior não pode derrubar o PR de patch/minor. Motivo concreto: o PR #52 do npm falhou (`ERESOLVE`) porque o grupo
juntou `laravel-vite-plugin` 3.2.0 com `fontaine` 1.0.3, e o plugin declara `fontaine ^0.8` como peer.

## Objetivos
- Grupos separados: patch+minor agrupados; major em PR próprio, com `cooldown` maior (14 dias).
- `fontaine` major ignorado até o `laravel-vite-plugin` aceitar a 1.x.
- Versão do PHP e do Node do CI lida dos Dockerfiles (que o Dependabot já atualiza) por uma ação local, em vez de fixa no YAML.
- Postgres/Redis do CI conferidos contra o `compose.yaml`, que o Dependabot (`docker-compose`) atualiza.
- Pinar o que sobrou sem SHA (`deploy-beta`) e adotar `actions/cache` v6.1.0 (provado verde no PR #53 do Dependabot).

## Não objetivos
- Auto-merge de PR do Dependabot (decisão do dono: ainda não; fica como opção).
- Tornar Postgres/Redis do CI dependentes do compose (workflow `services:` não aceita valor dinâmico): vale a conferência.

## Abordagens
1. **Fonte única nos Dockerfiles + conferência do compose (escolhida):** nada novo para o Dependabot aprender.
2. Arquivo `.node-version`/`.php-version`: o Dependabot não os atualiza. Descartada.
3. Renovar à mão: descartada, é o problema original.

## Decisões minhas (custo se erradas)
- Ler versão com `sed` dos `FROM`: se alguém mudar o formato do `FROM`, a ação falha alto (exit 1) em vez de usar versão errada.
- A conferência do compose faz o PR do Dependabot de Postgres/Redis falhar até alguém atualizar `tests.yml` junto: é intencional.
