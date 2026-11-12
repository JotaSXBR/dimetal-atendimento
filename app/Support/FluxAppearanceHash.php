<?php

namespace App\Support;

use Flux\AssetManager;
use RuntimeException;

/**
 * Hash (CSP) do script inline que o `@fluxAppearance` imprime no `<head>`. Calculado da diretiva do Flux (o que a página imprime), nunca do HTML
 * da resposta: hashear o que a página já traz liberaria qualquer script injetado. Se uma atualização do Flux mudar o script, o
 * hash acompanha sozinho (e `SecurityHeadersTest` confere que ele bate com o que a página renderiza).
 */
final class FluxAppearanceHash
{
    private static ?string $value = null;

    public static function value(): string
    {
        return self::$value ??= self::compute();
    }

    private static function compute(): string
    {
        $html = AssetManager::fluxAppearance(); // a mesma string que `@fluxAppearance` imprime; sem Blade::render (grava arquivo a cada chamada)

        if (! is_string($html) || preg_match('/<script[^>]*>(.*?)<\/script>/s', $html, $m) !== 1) {
            throw new RuntimeException('O Flux não imprime mais um script inline em @fluxAppearance; revisar a CSP.');
        }

        return 'sha256-'.base64_encode(hash('sha256', $m[1], true));
    }
}
