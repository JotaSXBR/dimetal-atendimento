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
}
