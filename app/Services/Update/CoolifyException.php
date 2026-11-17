<?php

namespace App\Services\Update;

use RuntimeException;

/** Falha ao falar com o Coolify, com mensagem em português para mostrar ao admin. Nunca carrega o token. */
final class CoolifyException extends RuntimeException
{
    /** @param  int  $status  Código HTTP; 0 quando nem houve resposta (config faltando, sem conexão). */
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }

    public static function fromResponse(int $status, mixed $body): self
    {
        $message = is_array($body) && is_string($body['message'] ?? null) ? $body['message'] : '';

        return new self(match (true) {
            $status === 401 => 'O Coolify recusou o token (token inválido ou expirado).',
            $status === 403 => preg_match('/permissions?:\s*([\w:]+)/i', $message, $m) === 1
                ? "O token não tem a permissão {$m[1]} no Coolify."
                : 'O token não tem permissão para esta operação no Coolify.',
            $status === 404 => 'Recurso não encontrado no Coolify.',
            default => "O Coolify respondeu com erro (HTTP {$status}).",
        }, $status);
    }
}
