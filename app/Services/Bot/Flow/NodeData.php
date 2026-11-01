<?php

namespace App\Services\Bot\Flow;

/** Os dados de um bloco como vêm do grafo (não confiáveis): cada leitura devolve o tipo pedido ou o padrão, nunca quebra. */
final class NodeData
{
    /** @param  array<string, mixed>  $raw */
    public function __construct(private readonly array $raw = []) {}

    public function string(string $key, string $default = ''): string
    {
        $value = $this->raw[$key] ?? null;

        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => $default,
        };
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** O texto do campo tem conteúdo (não só espaços). */
    public function filled(string $key): bool
    {
        return trim($this->string($key)) !== '';
    }

    /** O campo existe e não é nulo (ou vazio, no caso de número de seleção). */
    public function has(string $key): bool
    {
        $value = $this->raw[$key] ?? null;

        return $value !== null && $value !== '';
    }

    /** @return list<mixed> */
    public function list(string $key): array
    {
        $value = $this->raw[$key] ?? null;

        return is_array($value) ? array_values($value) : [];
    }

    /** @return array<string, mixed> */
    public function raw(): array
    {
        return $this->raw;
    }
}
