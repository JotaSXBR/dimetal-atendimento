<?php

namespace App\Services\Bot;

use Closure;

/** Troca `{{chave}}` pelo valor conhecido; chave desconhecida fica como está. Uma passada só: o valor inserido não é reinterpretado. */
final class Vars
{
    /** As variáveis que o bot sempre resolve (`BotRun::vars()`), com a descrição que o editor mostra no "Inserir variável". */
    public const BUILTIN = [
        'nome' => 'Nome do contato',
        'empresa' => 'Nome da empresa',
        'protocolo' => 'Protocolo do atendimento',
        'setor' => 'Setor escolhido',
        'horario' => 'Horário de funcionamento',
        'proximo_horario' => 'Próxima abertura',
    ];

    /** @param  array<string, string|int|Closure(): string>  $vars  valores `Closure` só são calculados quando usados */
    public static function render(string $template, array $vars): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function (array $m) use ($vars): string {
            $key = strtolower($m[1]);

            if (! array_key_exists($key, $vars)) {
                return $m[0];
            }

            $value = $vars[$key];

            return (string) ($value instanceof Closure ? $value() : $value);
        }, $template);
    }
}
