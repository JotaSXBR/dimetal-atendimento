<?php

namespace App\Support;

/** Um destino do menu: `gate` é `null` (todos), `supervisor` ou `admin`; `match` é o padrão de `routeIs()` do item ativo. */
final class NavItem
{
    public function __construct(
        public readonly string $label,
        public readonly string $route,
        public readonly ?string $gate,
        public readonly string $section,
        public readonly string $group,
        public readonly string $icon,
        public readonly string $match,
    ) {}
}
