<?php

namespace App\Services\Inbox;

/**
 * O que o evento do WAHA diz sobre o anexo. `ref` é o nome do arquivo no WAHA e é nulo quando o evento não o traz.
 */
final readonly class IncomingMedia
{
    public function __construct(
        public ?string $ref,
        public ?string $mime,
        public ?int $size,
    ) {}

    /**
     * O nome do arquivo vem de um evento externo e vira caminho no WAHA: só passa o que não sai do diretório de arquivos.
     * O `@` entra porque o WAHA nomeia o arquivo com o id serializado (`false_{chat}@c.us_{id}`); a rota o codifica.
     */
    public static function safeRef(?string $ref): ?string
    {
        $valid = $ref !== null
            && preg_match('/\A[A-Za-z0-9._@-]{1,255}\z/', $ref) === 1
            && trim($ref, '.') !== ''
            && ! str_contains($ref, '..');

        return $valid ? $ref : null;
    }
}
