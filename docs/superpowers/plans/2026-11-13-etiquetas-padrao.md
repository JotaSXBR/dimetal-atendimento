# Etiquetas padrão: plano

> **Para quem executa:** `superpowers:executing-plans`, inline (decisão do dono), RED antes do código.

**Meta:** instalação nova nasce com 15 etiquetas genéricas, uma única vez.
**Arquitetura:** `App\Services\Setup\DefaultTags` espelha `DefaultSectors`; agendador + `TagSeeder`.
**Spec:** `docs/superpowers/specs/2026-11-13-etiquetas-padrao-design.md`

## Global Constraints
- Só o beta; PR sem merge; commits em português; nomes de até 12 caracteres, únicos entre escopos.

## Review Focus
- Banco restaurado/instalação com etiqueta: só marca, sem duplicar.
- Etiqueta apagada pelo admin não volta.
- Duas chamadas concorrentes não duplicam (trava advisory).

### Tarefa 1: DefaultTags + seeder + agendador
**Files:** `app/Services/Setup/DefaultTags.php` (novo), `database/seeders/TagSeeder.php` (novo), `database/seeders/DatabaseSeeder.php`, `routes/console.php`, `tests/Feature/Domain/DefaultTagsTest.php` (novo).
**Interfaces:** `DefaultTags::ALL` (lista de `[nome, TagScope, TagColor]`), `MARK`, `isLoaded(): bool`, `install(): bool`.
- [ ] RED: testes (cria as 15 com escopo e cor; nomes únicos e ≤12; segunda chamada não recria apagada; banco com etiqueta só grava marca; seeder idempotente; agendamento só enquanto sem marca).
- [ ] GREEN: implementar como `DefaultSectors`, guarda `! Tag::query()->exists()`.
- [ ] Rodar afetados; Pint; commit.
