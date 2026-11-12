<?php

namespace App\Services\Backup\Restore;

/**
 * O pedido de restauração que vai para a fila.
 *
 * `mode` = `restore`: `source` é `bucket:<nome do backup>` ou `upload:<ulid>`. `mode` = `switch`: `source` é o nome de um banco
 * guardado (`<db>_antes_...`), validado contra a lista real na hora de executar.
 */
final readonly class RestoreRequest
{
    public function __construct(
        public string $mode,
        public string $source,
        public string $actor,
    ) {}
}
