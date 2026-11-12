<?php

namespace App\Services\Backup\Restore;

use RuntimeException;

/** A troca dos bancos não aconteceu; quando isto é lançado o banco ativo continua sendo o de antes. */
final class SwapFailed extends RuntimeException {}
