<?php

namespace App\Services\Backup;

use RuntimeException;

/** O backup não saiu. A mensagem é segura para gravar e mostrar: nunca leva a senha do banco nem a linha de comando. */
final class DumpFailed extends RuntimeException {}
