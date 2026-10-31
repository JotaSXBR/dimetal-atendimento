# CI paralelo e hook de push (design curto)

## Intenção
O `tests` leva ~2 min 15 s: 12 s de containers, 12 s de setup e 99 s de `ci:check`, que roda Pint (11 s), Larastan (23 s),
PHPMD (11 s) e testes com cobertura em sequência. O PR deve esperar só o job mais lento, e o erro de estilo/tipo deve
aparecer em segundos, antes do push.

## Objetivos
- Pint, Larastan e PHPMD num job próprio, sem Postgres/Redis, em paralelo com os testes.
- Cache do resultado do Larastan e do Pint entre execuções.
- Hook `pre-push` versionado que roda `lint:check` e `types:check`, ativado com um comando.
- Proteção da `main` exigindo os novos checks.

## Não objetivos / anti-objetivos
- Runners pagos, sharding em várias máquinas, `paratest` e "só o que mudou": o ganho não paga o custo nesta suíte (112 arquivos, ~55 s).
- Suíte de testes no hook (precisa de Postgres): fica só no remoto.
- Reduzir cobertura mínima ou afrouxar qualquer verificação.
- Mexer no `deploy-beta`: ele depende do workflow `tests` (nome mantido); a estrutura do `deploy-beta` não muda.

## Abordagens consideradas
1. **Jobs paralelos + caches + pre-push (escolhida):** menor mudança que corta o caminho crítico para ~1 min 30 s.
2. Idem + `paratest`: custo de banco por processo e risco de teste dependente de ordem; adiar até medir.
3. Só hook local: remoto continua em 2 min 15 s.

## Desenho
- `tests.yml`: novo job `static` (Pint `--test`, Larastan, PHPMD) com `composer install` e cache de `.phpstan.cache`/Pint;
  o job `tests` passa a rodar só `php artisan test --coverage --min=80` (script `composer coverage`), sem repetir as checagens estáticas.
  `audit` permanece. Mudo `ci:check` para continuar completo localmente (usado por quem quiser tudo de uma vez).
- Hook em `.githooks/pre-push`, ativado por `git config core.hooksPath .githooks` (script `composer hooks:install`).
- Proteção da `main`: checks exigidos passam de `tests` para `tests`, `static`; manter `strict`. Exigir PR antes do merge (sem aprovações obrigatórias, `enforce_admins` já ligado): incluído, por decisão do dono.

## Decisões minhas (custo se erradas)
- Nome do job novo `static`: renomear depois exige reajustar a proteção.
- Exigir `static` na proteção: se o job ficar instável, bloqueia merge até corrigir.

## Fica para o implementador
Chave exata de cache, versão dos actions (fixar por SHA como os demais), texto do hook.
