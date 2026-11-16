# Fluxie Inbox

Central de atendimento por WhatsApp: fila por setor, tickets, automação sem código, SLA e auditoria.

## Recursos principais

- Fila de atendimento por setor, com tickets e transferência entre atendentes.
- Automação sem código (chatbot determinístico).
- SLA por ticket.
- Auditoria das ações.
- Mensagens em tempo real no painel.
- Backup e restauração do banco pela própria tela.

## Stack

| Camada | Tecnologia |
|---|---|
| Backend | PHP 8.5, Laravel 13, Livewire 4, Fortify |
| UI | Blade, Livewire, Flux, Tailwind CSS 4 |
| Banco | PostgreSQL 18 |
| Cache/fila | Redis 8 (`predis` no Windows, `phpredis` no Linux) |
| WhatsApp | WAHA self-hosted, engine GOWS |
| Build | Vite, Node 24 LTS |
| Qualidade | PHPUnit 12, Larastan 7, Pint, PHPMD |

## Requisitos locais

- PHP 8.5 com `pdo_pgsql`, `pgsql`, `mbstring`, `intl`, `zip`, `gd`
- Composer 2
- Node 24 LTS
- Docker (para PostgreSQL e Redis)

## Rodando localmente

```powershell
docker compose up -d
composer setup
php artisan db:seed
npm run dev
php artisan serve
php artisan queue:work --queue=outbound,inbound,realtime,default,google,history,media
```

A aplicação fica em `http://localhost:8000`. O `queue:work` é necessário para o webhook do WhatsApp:
os eventos entram na fila do Redis antes de serem processados. As respostas do painel vão para a fila `outbound`
(em produção, um processo próprio); localmente um worker só atende todas as filas.

O `composer setup` instala dependências, cria o `.env`, gera a `APP_KEY`, roda as migrações e
compila os assets. Os containers do `compose.yaml` criam `fluxie` e `fluxie_test`;
`DB_USERNAME=fluxie` e `DB_PASSWORD=secret` já vêm no `.env.example`.

O `db:seed` cria os cinco setores e, se `ADMIN_EMAIL`/`ADMIN_PASSWORD` estiverem no `.env`, o
administrador inicial. Outros usuários: `php artisan app:create-user email@exemplo.com.br "Nome"
--role=atendente`.

## Instalação no Coolify

Em preparação. O roteiro atual de deploy está em [`docs/deploy.md`](docs/deploy.md).

## Testes e qualidade

```powershell
composer test            # Pint + Larastan + PHPMD + PHPUnit
composer lint            # corrige estilo
composer types:check     # análise estática (Larastan, nível max)
composer complexity      # complexidade e tamanho (PHPMD)
composer security:audit  # vulnerabilidades em dependências (composer audit + npm audit)
composer ci:check        # o que o CI roda: estilo, tipos, complexidade e testes com cobertura
```

| Verificação | Ferramenta | Limite |
|---|---|---|
| Estilo | Pint, preset Laravel | sem diferenças |
| Tipos | Larastan, nível `max` | 0 erros, sem baseline nem `ignore` |
| Complexidade | PHPMD (`phpmd.xml`) | ciclomática 10, NPath 200, classe 50 |
| Tamanho | PHPMD (`phpmd.xml`) | método 100 linhas, classe 1000, 10 parâmetros, 25 métodos |
| Código morto | PHPMD | campos, métodos e variáveis sem uso |
| Cobertura | PHPUnit com `pcov` (só no CI) | mínimo 80% (medido: 83,8%), script `coverage` |
| Dependências | `composer audit`, `npm audit` | sem vulnerabilidade `high` ou acima; job `audit` à parte |

Os limites do PHPMD são os padrão da ferramenta; a única exceção é `TooManyPublicMethods` (15), porque o
`WahaClient` tem um método por endpoint do WAHA. Subir um limite exige justificar no `phpmd.xml`.

## Fluxo de branches

- `main` protege o ambiente de produção; só recebe merge com CI verde.
- `beta` publica o ambiente de testes no Coolify, com número de WhatsApp separado.
## Licença

MIT. Veja [LICENSE](LICENSE).
