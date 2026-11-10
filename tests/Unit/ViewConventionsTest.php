<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Convenções de UI que não podem voltar para trás (selo único, botões só do Flux). */
class ViewConventionsTest extends TestCase
{
    /** @return array<string, string> caminho relativo → conteúdo, de todas as views Blade */
    private function views(): array
    {
        $root = dirname(__DIR__, 2).'/resources/views';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $out = [];

        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $out[str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1))] = (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    public function test_views_nao_usam_mais_status_color_nem_selo_a_mao(): void
    {
        // Contadores numéricos (não são estado): o selo de SLA da sidebar e o contador do sino.
        $contadores = ['layouts/app/sidebar.blade.php', 'livewire/alerts/bell.blade.php'];

        foreach ($this->views() as $path => $html) {
            $this->assertStringNotContainsString('$statusColor', $html, "{$path}: o tom vem do enum (tone()), não de closure local");
            $this->assertDoesNotMatchRegularExpression('/<flux:badge[^>]*\scolor=/', $html, "{$path}: flux:badge com cor da paleta; use <x-status-badge>");

            if ($path === 'components/status-badge.blade.php' || in_array($path, $contadores, true)) {
                continue;
            }

            foreach (explode("\n", $html) as $n => $line) {
                $this->assertDoesNotMatchRegularExpression('/rounded-full[^"\']*\spx-[^"\']*(bg|text)-(ok|warn|danger|bot)\b|(bg|text)-(ok|warn|danger|bot)\b[^"\']*\srounded-full[^"\']*\spx-/', $line, $path.':'.($n + 1).': pílula de estado feita à mão; use <x-status-badge>');
            }
        }
    }
}
