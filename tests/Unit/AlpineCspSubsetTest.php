<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guarda das expressões de atributo Alpine/Livewire: o build CSP do Alpine (`livewire.csp.js`) não avalia strings e só aceita um
 * subconjunto (ver docs/superpowers/specs/2026-11-12-csp-sem-unsafe-eval-design.md). Este teste varre `resources/views` e falha com
 * arquivo:linha para qualquer expressão fora dele, para que a chave `LIVEWIRE_CSP_SAFE` possa ficar ligada.
 */
class AlpineCspSubsetTest extends TestCase
{
    /**
     * Views ainda não convertidas (cada tarefa do plano esvazia a lista). Ao terminar, fica vazia.
     *
     * @var list<string>
     */
    private const PENDENTES = [
        'components/passkey-registration.blade.php',
        'components/passkey-verify.blade.php',
        'livewire/automation/editor.blade.php',
        'livewire/inbox/transcript.blade.php',
        'livewire/settings/company.blade.php',
        'livewire/settings/sectors.blade.php',
        'pages/auth/two-factor-challenge.blade.php',
        'pages/settings/⚡alerts.blade.php',
        'pages/settings/⚡two-factor-setup-modal.blade.php',
    ];

    /** @return array<string, array{string, list<string>}> */
    public static function amostras(): array
    {
        return [
            'seta' => ['<div x-init="$nextTick(() => a())">', ['seta']],
            'crase' => ['<div x-bind:style="`left:${x}px`">', ['crase']],
            'encadeamento opcional' => ['<div x-show="$wire.sim?.log">', ['?.']],
            'coalescência nula' => ['<div x-text="a ?? 1">', ['??']],
            'duas instruções' => ['<button @click="a = 1; b()">', ['instruções']],
            'if' => ['<div x-on:keydown.enter="if (a) { b() }">', ['palavra-chave', 'método no literal']],
            'global do navegador' => ['<div x-data="{ v: localStorage.getItem(\'a\') }">', ['global']],
            'JSON.parse' => ['<div x-data="f(JSON.parse(\'{}\'))">', ['global']],
            'método no x-data inline' => ['<div x-data="{ open: false, toggle() { this.open = !this.open } }">', ['método no literal']],
            'atribuição a nó do DOM' => ['<img x-on:error="$el.style.display = \'none\'">', ['DOM']],
            'atribuição ao alvo do evento' => ['<input x-on:change="$event.target.value = \'\'">', ['DOM']],
            'new' => ['<div x-on:click="x(new Foo())">', ['palavra-chave']],
            'espalhamento' => ['<div x-data="{ ...a }">', ['spread']],
            'ok: ternário, chamada e comparação' => ['<div x-show="mode === \'reply\' && !filled ? a.b(1, 2) : c">', []],
            'ok: atribuição a objeto comum e índice' => ['<input x-model="selected.data[field.key]" x-on:click="open = !open">', []],
            'ok: crase dentro de string' => ['<button x-on:click="wrap(\'```\')">', []],
            'ok: ponto e vírgula final' => ['<button x-on:click="go();">', []],
            'ok: x-for com in' => ['<template x-for="(line, index) in lines">', []],
            'ok: método chamado delete' => ['<button x-on:click="$wire.delete(1)">', []],
            'ok: wire:click com método delete (o Livewire prefixa $wire.)' => ['<button wire:click="delete(1)">', []],
            'delete solto fora do wire:' => ['<button x-on:click="delete(1)">', ['palavra-chave']],
            'ok: prop Blade em componente' => ['<flux:input :value="$a ?? null" :label="__(\'x\')" />', []],
            'ok: eco Blade e @js' => ['<div x-data="{ name: @js($sector->name), id: {{ $id }} }">', []],
            'ok: wire:model é caminho de propriedade' => ['<input wire:model="days.{{ $d }}.opens">', []],
            '@js de array vira JSON.parse' => ['<div x-data="flowEditor($wire, @js($config))">', ['@js não escalar']],
            'ok: @js de string conhecida' => ['<div x-data="{ name: @js($sector->name) }">', []],
            'ok: wire:confirm é texto' => ['<button wire:confirm="Apagar? Não há volta; ok.">', []],
        ];
    }

    /** @param  list<string>  $esperadas */
    #[DataProvider('amostras')]
    public function test_o_scanner_reconhece_o_subconjunto(string $blade, array $esperadas): void
    {
        $regras = array_values(array_unique(array_column(self::violacoes($blade), 'regra')));
        sort($regras);
        sort($esperadas);

        $this->assertSame($esperadas, $regras);
    }

    public function test_nenhuma_view_usa_expressao_fora_do_subconjunto_do_alpine_csp(): void
    {
        $achados = [];

        foreach (self::views() as $relativo => $conteudo) {
            if (in_array($relativo, self::PENDENTES, true)) {
                continue;
            }

            foreach (self::violacoes($conteudo) as $v) {
                $achados[] = "{$relativo}:{$v['linha']} [{$v['atributo']}] {$v['regra']}";
            }
        }

        $this->assertSame([], $achados, "Expressões fora do subconjunto do build CSP do Alpine:\n".implode("\n", $achados));
    }

    public function test_a_lista_de_pendentes_nao_apodrece(): void
    {
        $views = self::views();

        foreach (self::PENDENTES as $relativo) {
            $this->assertArrayHasKey($relativo, $views, "{$relativo} não existe mais: tirar de PENDENTES");
            $this->assertNotSame([], self::violacoes($views[$relativo]), "{$relativo} já está limpa: tirar de PENDENTES");
        }
    }

    /** @return array<string, string> caminho relativo a resources/views => conteúdo */
    private static function views(): array
    {
        $raiz = dirname(__DIR__, 2).'/resources/views';
        $views = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS)) as $arquivo) {
            if (str_ends_with($arquivo->getFilename(), '.blade.php')) {
                $views[str_replace('\\', '/', substr($arquivo->getPathname(), strlen($raiz) + 1))] = (string) file_get_contents($arquivo->getPathname());
            }
        }

        ksort($views);

        return $views;
    }

    /** Regras do parser instalado (`vendor/livewire/livewire/dist/livewire.csp.js`): o que ele não tokeniza, não parseia ou proíbe. */
    private const REGRAS = [
        'seta' => '/=>/',
        'crase' => '/`/',
        'spread' => '/\.\.\./',
        '?.' => '/\?\./',
        '??' => '/\?\?/',
        'instruções' => '/;\s*\S/',
        'palavra-chave' => '/(?<![\w$.])(?:if|else|const|let|var|return|await|async|function|new|typeof|instanceof|delete|void|throw|try|catch|for|while|switch|class|yield)\b/',
        'global' => '/(?<![\w$.])(?:JSON|localStorage|sessionStorage|window|document|Math|Date|Notification|navigator|console|Number|String|Boolean|parseInt|parseFloat|Object|Array|setTimeout|setInterval|location|history|fetch|Promise|Intl|globalThis)\b/',
        'DOM' => '/(?<![\w$])\$(?:el|event|refs)\b[\w$.\[\]]*\s*(?:=(?!=)|\+=|-=)/',
        'método no literal' => '/[\w$]+\s*\([^()]*\)\s*\{/',
    ];

    /** Atributos cujo valor o Alpine não avalia como JS (texto, nome de ref, classes, seletor). */
    private const SEM_EXPRESSAO = '/^(?:x-ref|x-cloak|x-ignore|x-teleport|x-transition)/';

    /**
     * `@js($x)` com array ou objeto vira `JSON.parse('...')`, e `JSON` não existe no parser CSP; com string, número ou booleano é um
     * literal e vale. O tipo não se vê no Blade, então só passa o que está nesta lista de usos escalares conhecidos: um `@js` novo
     * dentro de atributo exige decidir (escalar entra aqui; array ou objeto vai por `data-*`, ver `flowEditor`).
     */
    private const JS_ESCALARES = [
        '$model',
        '$action',
        '$sector->name',
        '$message->body',
        '$qrValue',
        '\App\Settings\CompanySettings::logoUrl()',
    ];

    /** @return list<array{linha: int, atributo: string, regra: string}> */
    private static function jsNaoEscalares(string $blade): array
    {
        $achados = [];

        preg_match_all('/(?<![\w:@.\-])((?:x-[\w:.\-]+)|(?:@[\w:.\-]+)|(?::[\w:.\-]+)|(?:wire:(?:click|show|text|submit|keydown|keyup|change)[\w:.\-]*))\s*=\s*"([^"]*)"/', $blade, $atributos, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($atributos as $a) {
            preg_match_all('/@js\(((?:[^()]|\([^()]*\))*)\)/', $a[2][0], $usos);

            foreach ($usos[1] as $argumento) {
                if (! in_array(trim($argumento), self::JS_ESCALARES, true)) {
                    $achados[] = ['linha' => substr_count($blade, "\n", 0, $a[1][1]) + 1, 'atributo' => $a[1][0], 'regra' => '@js não escalar'];
                }
            }
        }

        return $achados;
    }

    /** @return list<array{linha: int, atributo: string, regra: string}> */
    private static function violacoes(string $blade): array
    {
        $achados = self::jsNaoEscalares($blade);
        $blade = self::semBlade($blade);

        preg_match_all(
            '/(?<![\w:@.\-])((?:x-[\w:.\-]+)|(?:@[\w:.\-]+)|(?::[\w:.\-]+)|(?:wire:(?:click|show|text|submit|keydown|keyup|change)[\w:.\-]*))\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/',
            $blade,
            $atributos,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($atributos as $a) {
            [$nome, $inicio] = $a[1];
            $valor = ($a[2][1] ?? -1) >= 0 ? $a[2][0] : ($a[3][0] ?? '');

            if (preg_match(self::SEM_EXPRESSAO, $nome) === 1) {
                continue;
            }

            if ($nome[0] === ':' && self::tagDe($blade, $inicio) !== null && str_contains((string) self::tagDe($blade, $inicio), ':')) {
                continue; // `:prop="php"` em <flux:...>/<livewire:...> é prop Blade, não Alpine
            }

            if ($nome[0] === ':' && str_starts_with((string) self::tagDe($blade, $inicio), 'x-')) {
                continue;
            }

            $semTexto = (string) preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', "''", $valor);
            if (str_starts_with($nome, 'wire:')) {
                // O Livewire prefixa identificadores soltos com `$wire.` antes do Alpine: `delete(1)` vira `$wire.delete(1)` (membro, vale).
                $semTexto = (string) preg_replace('/(?<![\w$.])delete(?=\()/', '$wire.delete', $semTexto);
            }

            $linha = substr_count($blade, "\n", 0, $inicio) + 1;

            foreach (self::REGRAS as $regra => $padrao) {
                if (preg_match($padrao, $semTexto) === 1) {
                    $achados[] = ['linha' => $linha, 'atributo' => $nome, 'regra' => $regra];
                }
            }
        }

        return $achados;
    }

    /** O nome da tag que contém o atributo na posição `$posicao` (ou nulo). */
    private static function tagDe(string $blade, int $posicao): ?string
    {
        $abre = strrpos(substr($blade, 0, $posicao), '<');

        return $abre !== false && preg_match('/^<([A-Za-z][\w:.\-]*)/', substr($blade, $abre, 80), $m) === 1 ? $m[1] : null;
    }

    /** Troca o que o Blade resolve no servidor (`{{ }}`, `{!! !!}`, `@js()`, `@json()`) por `0`, mantendo as quebras de linha. */
    private static function semBlade(string $blade): string
    {
        $mantemLinhas = fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n"));
        $blade = (string) preg_replace_callback('/\{\{--.*?--\}\}/s', $mantemLinhas, $blade);
        $blade = (string) preg_replace_callback('/\{!!.*?!!\}|\{\{.*?\}\}/s', fn (array $m): string => '0'.str_repeat("\n", substr_count($m[0], "\n")), $blade);

        return (string) preg_replace('/@(?:js|json)\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/', '0', $blade);
    }
}
