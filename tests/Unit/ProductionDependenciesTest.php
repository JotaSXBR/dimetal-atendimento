<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** A imagem roda `composer install --no-dev`: classe importada em `app/` não pode vir só de pacote de desenvolvimento. */
class ProductionDependenciesTest extends TestCase
{
    public function test_app_nao_importa_classe_de_pacote_so_de_desenvolvimento(): void
    {
        $root = dirname(__DIR__, 2);
        $lock = json_decode((string) file_get_contents($root.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $prod = $this->prefixes($lock['packages']);
        // Os prefixos desta aplicação (`App\`, `Database\...`) ficam fora: o laravel/pint também os declara.
        $own = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $devOnly = array_diff_key($this->prefixes($lock['packages-dev']), $prod, $this->prefixes([$own]));

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app', \FilesystemIterator::SKIP_DOTS));
        $missing = [];

        foreach ($files as $file) {
            preg_match_all('/^use (?:function |const )?([A-Za-z0-9_\\\\]+)/m', (string) file_get_contents($file->getPathname()), $m);

            foreach ($m[1] as $class) {
                foreach ($devOnly as $prefix => $package) {
                    if (str_starts_with($class.'\\', $prefix) && ! $this->coveredByProd($class, $prod)) {
                        $missing[$package] = $class;
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'Pacote só em require-dev usado em app/: mova para require.');
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     * @return array<string, string> prefixo PSR-4 → pacote
     */
    private function prefixes(array $packages): array
    {
        $out = [];

        foreach ($packages as $package) {
            foreach (array_keys(($package['autoload']['psr-4'] ?? []) + ($package['autoload']['psr-0'] ?? [])) as $prefix) {
                $out[rtrim($prefix, '\\').'\\'] = $package['name'];
            }
        }

        return $out;
    }

    /** @param  array<string, string>  $prod */
    private function coveredByProd(string $class, array $prod): bool
    {
        foreach (array_keys($prod) as $prefix) {
            if (str_starts_with($class.'\\', $prefix)) {
                return true;
            }
        }

        return false;
    }
}
