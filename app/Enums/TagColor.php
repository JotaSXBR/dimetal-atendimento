<?php

namespace App\Enums;

/**
 * Paleta fixa das etiquetas (sem cor livre). Nenhum tom é verde, âmbar ou vermelho: essas são as cores de estado do app.
 * Os estilos de cada tom ficam em `resources/css/app.css` (`.tag-<tom>`, claro e escuro) e são medidos em `ThemeTokensTest`.
 */
enum TagColor: string
{
    case AZUL = 'azul';
    case CEU = 'ceu';
    case ANIL = 'anil';
    case VIOLETA = 'violeta';
    case LILAS = 'lilas';
    case FUCSIA = 'fucsia';
    case ROSA = 'rosa';
    case TURQUESA = 'turquesa';
    case CIANO = 'ciano';
    case ARDOSIA = 'ardosia';
    case PEDRA = 'pedra';

    public function label(): string
    {
        return match ($this) {
            self::AZUL => 'Azul',
            self::CEU => 'Céu',
            self::ANIL => 'Anil',
            self::VIOLETA => 'Violeta',
            self::LILAS => 'Lilás',
            self::FUCSIA => 'Fúcsia',
            self::ROSA => 'Rosa',
            self::TURQUESA => 'Turquesa',
            self::CIANO => 'Ciano',
            self::ARDOSIA => 'Ardósia',
            self::PEDRA => 'Pedra',
        };
    }

    /** O tom como bolinha do seletor: o mesmo `--tag-line` do tema claro. */
    public function swatch(): string
    {
        return match ($this) {
            self::AZUL => '#2563eb',
            self::CEU => '#0284c7',
            self::ANIL => '#4f46e5',
            self::VIOLETA => '#7c3aed',
            self::LILAS => '#9333ea',
            self::FUCSIA => '#c026d3',
            self::ROSA => '#db2777',
            self::TURQUESA => '#0d9488',
            self::CIANO => '#0891b2',
            self::ARDOSIA => '#64748b',
            self::PEDRA => '#71717a',
        };
    }

    public static function default(): self
    {
        return self::ARDOSIA;
    }

    /** O tom mais próximo de uma cor `#RRGGBB` antiga; texto que não é hex cai no padrão. */
    public static function nearest(string $hex): self
    {
        if (preg_match('/^#[0-9a-f]{6}$/i', $hex) !== 1) {
            return self::default();
        }

        $target = self::rgb($hex);
        $best = self::default();
        $bestDistance = PHP_INT_MAX;

        foreach (self::cases() as $tone) {
            $distance = array_sum(array_map(fn (int $a, int $b): int => ($a - $b) ** 2, $target, self::rgb($tone->swatch())));

            if ($distance < $bestDistance) {
                [$best, $bestDistance] = [$tone, $distance];
            }
        }

        return $best;
    }

    /** @return array{int, int, int} */
    private static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }
}
