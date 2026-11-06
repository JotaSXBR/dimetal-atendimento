<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ThemeTokensTest extends TestCase
{
    private const TOKENS = ['ink', 'ink-2', 'ink-3', 'line', 'canvas', 'surface', 'accent', 'accent-content', 'accent-foreground', 'accent-soft', 'accent-deep', 'warn', 'danger', 'ok', 'bot', 'ink-foreground', 'danger-foreground', 'warn-foreground'];

    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
    }

    /** @return array<string, string> token → hex */
    private function hexes(string $block): array
    {
        preg_match_all('/--color-([a-z0-9-]+):\s*(#[0-9a-fA-F]{6})\s*;/', $block, $m, PREG_SET_ORDER);

        return array_column($m, 2, 1);
    }

    /** @return array{light: array<string, string>, dark: array<string, string>} */
    private function themes(): array
    {
        $css = $this->css();
        preg_match('/\.dark\s*\{([^}]*)\}/', $css, $dark);
        $light = $this->hexes((string) preg_replace('/\.dark\s*\{[^}]*\}/', '', $css));

        return ['light' => $light, 'dark' => [...$light, ...$this->hexes($dark[1] ?? '')]];
    }

    private function luminance(string $hex): float
    {
        $channels = array_map(function (string $c): float {
            $v = hexdec($c) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function ratio(string $a, string $b): float
    {
        [$hi, $lo] = [max($this->luminance($a), $this->luminance($b)), min($this->luminance($a), $this->luminance($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    public function test_cada_token_semantico_tem_valor_no_claro_e_no_escuro(): void
    {
        preg_match('/\.dark\s*\{([^}]*)\}/', $this->css(), $dark);
        $light = $this->themes()['light'];
        $overrides = $this->hexes($dark[1] ?? '');

        foreach (self::TOKENS as $token) {
            $this->assertArrayHasKey($token, $light, "claro: {$token}");
            $this->assertArrayHasKey($token, $overrides, "escuro: {$token}");
        }
    }

    public function test_a_marca_e_amarela_e_o_verde_antigo_sumiu(): void
    {
        $this->assertSame('#facc15', $this->themes()['light']['accent'] ?? null);
        $this->assertStringNotContainsString('#12705a', strtolower($this->css()));
    }

    public function test_contraste_minimo_de_texto_nos_dois_temas(): void
    {
        foreach ($this->themes() as $theme => $t) {
            foreach (['canvas', 'surface'] as $ground) {
                foreach (['ink', 'ink-2', 'ink-3', 'accent-content', 'warn', 'danger', 'ok', 'bot'] as $text) {
                    $this->assertGreaterThanOrEqual(4.5, $this->ratio($t[$text], $t[$ground]), "{$theme}: {$text} sobre {$ground}");
                }
            }
            $this->assertGreaterThanOrEqual(4.5, $this->ratio($t['accent-foreground'], $t['accent']), "{$theme}: accent-foreground sobre accent");
        }
    }

    public function test_movimento_reduzido_zera_transicoes(): void
    {
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $this->css());
        $this->assertStringContainsString('--duration-base: 200ms', $this->css());
    }

    public function test_cada_fundo_de_token_tem_texto_proprio_com_contraste(): void
    {
        foreach ($this->themes() as $theme => $t) {
            foreach (['accent', 'ink', 'danger', 'warn'] as $ground) {
                $this->assertGreaterThanOrEqual(4.5, $this->ratio($t[$ground.'-foreground'], $t[$ground]), "{$theme}: {$ground}-foreground sobre {$ground}");
            }
        }
    }

    public function test_aviso_e_erro_legiveis_sobre_o_proprio_tom_de_fundo(): void
    {
        foreach ($this->themes() as $theme => $t) {
            foreach (['canvas', 'surface'] as $ground) {
                foreach (['warn', 'danger', 'ok', 'bot'] as $tone) {
                    foreach ($tone === 'warn' ? [0.10, 0.20] : [0.10] as $alpha) { // warn/20 é o realce da aba "Nota privada"
                        $tint = $this->mix($t[$tone], $t[$ground], $alpha);
                        $this->assertGreaterThanOrEqual(4.5, $this->ratio($t[$tone], $tint), "{$theme}: {$tone} sobre {$tone}/".(int) ($alpha * 100)." em {$ground}");
                    }
                }
            }
        }
    }

    public function test_nenhuma_view_usa_texto_branco_fixo_sobre_fundo_de_token(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views')) as $file) {
            if (! str_ends_with((string) $file, '.blade.php')) {
                continue;
            }
            foreach (file((string) $file) ?: [] as $n => $line) {
                if (preg_match('/bg-(?:accent|ink|danger|warn|ok|bot)(?![\w\/-])/', $line) && str_contains($line, 'text-white')) {
                    $offenders[] = basename((string) $file).':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'use text-<token>-foreground no lugar de text-white');
    }

    /** Mistura `$top` sobre `$bottom` com a opacidade dada (a classe `bg-x/10` do Tailwind). */
    private function mix(string $top, string $bottom, float $alpha): string
    {
        $a = array_map('hexdec', str_split(ltrim($top, '#'), 2));
        $b = array_map('hexdec', str_split(ltrim($bottom, '#'), 2));

        return '#'.implode('', array_map(fn (int $i): string => sprintf('%02x', (int) round($a[$i] * $alpha + $b[$i] * (1 - $alpha))), [0, 1, 2]));
    }

    public function test_marca_amarela_nunca_e_cor_de_texto_nem_o_texto_vira_fundo(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views')) as $file) {
            if (! str_ends_with((string) $file, '.blade.php')) {
                continue;
            }
            foreach (file((string) $file) ?: [] as $n => $line) {
                // `text-accent` é o amarelo de FUNDO (1,5:1 sobre branco); texto de marca é `text-accent-content`, e `bg-accent-content` é cor de texto.
                if (preg_match('/(?<![\w-])text-accent(?![\w-])|bg-accent-content/', $line)) {
                    $offenders[] = basename((string) $file).':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_botao_perigo_do_flux_tem_texto_branco_legivel_nos_dois_temas(): void
    {
        // O Flux pinta o botão `danger` com red-500 (claro) e red-600 (escuro), texto branco, e o hover troca de um para o outro.
        preg_match_all('/--color-red-(500|600):\s*(#[0-9a-fA-F]{6})\s*;/', $this->css(), $m);
        $red = array_combine($m[1], $m[2]);

        $this->assertArrayHasKey('500', $red);
        $this->assertArrayHasKey('600', $red);
        $this->assertNotSame($red['500'], $red['600'], 'o hover precisa mudar de tom');
        $this->assertGreaterThanOrEqual(4.5, $this->ratio('#ffffff', $red['500']), 'claro: branco sobre red-500');
        $this->assertGreaterThanOrEqual(4.5, $this->ratio('#ffffff', $red['600']), 'escuro: branco sobre red-600');
    }

    public function test_foco_do_teclado_tem_anel_proprio_visivel_nos_dois_temas(): void
    {
        $this->assertMatchesRegularExpression('/:focus-visible[^{]*\{[^}]*outline:\s*2px solid var\(--color-accent-content\)/', $this->css());

        foreach ($this->themes() as $theme => $t) {
            foreach (['canvas', 'surface'] as $ground) {
                $this->assertGreaterThanOrEqual(3.0, $this->ratio($t['accent-content'], $t[$ground]), "{$theme}: anel de foco sobre {$ground}");
            }
        }
    }

    public function test_views_nao_usam_amber_cru_o_aviso_e_o_token_warn(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views')) as $file) {
            if (! str_ends_with((string) $file, '.blade.php')) {
                continue;
            }
            foreach (file((string) $file) ?: [] as $n => $line) {
                if (preg_match('/(?<![\w-])(?:bg|text|border|ring|fill)-amber-\d/', $line)) {
                    $offenders[] = basename((string) $file).':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'amber-* não acompanha o tema escuro: use bg-warn/10, border-warn/30, text-warn');
    }
}
