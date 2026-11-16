# Imagem base em Debian 13 e sempre atualizada: design curto

Data: 16/11/2026. Branch `fix/imagem-trixie-atualizada`, a partir da `main` (`5e88d2c`). Decisões delegadas pelo dono ("pesquise antes;
decisões difíceis, decida pela pesquisa"), registradas aqui.

## Por quê

O `security-scan` de 16/11 falhou com CVE CRITICAL no `linux-libc-dev` (6.1.190-1, corrigido em 6.1.192-1). Republicar a base não
resolveu, por dois motivos achados na pesquisa:

1. A `serversideup/php:8.5.12-fpm-nginx-*` foi publicada em 12/11, antes das correções do Debian; o nosso `docker/base/Dockerfile` não
   atualiza os pacotes do sistema. Trivy (mesmos critérios do CI: HIGH/CRITICAL com correção) na imagem de cima: 156 achados no bookworm,
   40 no trixie. Com `apt-get upgrade`, 0 nos dois.
2. O `base-image` usa cache do GitHub Actions (`cache-from: type=gha`): a republicação semanal reaproveita as camadas e não traz nada novo
   (a de 16/11 levou 32 s). Uma camada de atualização sem quebra de cache ficaria presa do mesmo jeito.

E o Debian 12 (bookworm) saiu do suporte regular em 12/07/2026 (só LTS até 30/06/2028); o Debian 13 (trixie) é o estável atual.

## Abordagens

1. **Trixie + atualização na base com quebra de cache (escolhida).** Base `8.5.12-fpm-nginx-trixie`; camada `apt-get upgrade` depois
   das extensões, com `ARG SECURITY_REFRESH` que o workflow preenche com o id da execução (só essa camada refaz; as extensões seguem em
   cache). Tira o remendo `--only-upgrade apt` do Dockerfile da aplicação. Corrige a causa e acompanha o Debian toda semana.
2. Bookworm + a mesma atualização: resolve hoje, mas fica numa versão já em LTS e terá de migrar de qualquer jeito.
3. Remendo no Dockerfile da aplicação (`--only-upgrade apt linux-libc-dev`): é o que o dono pediu para evitar; cada CVE novo pede outro
   remendo, e a causa (cache) continua.

## O que muda

- `docker/base/Dockerfile`: `FROM serversideup/php:8.5.12-fpm-nginx-trixie`; camada de atualização com `ARG SECURITY_REFRESH`.
- `.github/workflows/base-image.yml`: `build-args: SECURITY_REFRESH=${{ github.run_id }}`.
- `docker/Dockerfile`: sai o remendo do `apt`; o repositório PGDG usa o codinome do sistema (`/etc/os-release`), para o build não quebrar
  com a base antiga (bookworm) nem com a nova (trixie) durante a troca.
- `.github/workflows/security-scan.yml`: constrói a base do próprio branch e a usa no lugar da publicada (`--build-context`), para o PR
  provar a correção e o scan semanal olhar uma base atualizada.

## Riscos

- Corrida no merge: o deploy do beta pode montar a imagem com a base antiga se o `base-image` ainda não tiver publicado. A imagem antiga
  funciona (o PGDG pelo codinome cobre os dois); conferir depois do merge e refazer o deploy se precisar.
- Bibliotecas novas (ICU, libpq, GD do trixie): conferir extensões, `pg_dump --version` e a suíte de testes; subir o beta e olhar logs.

## Fora

Trocar a versão do PHP; Alpine; mudar o fluxo de deploy.
