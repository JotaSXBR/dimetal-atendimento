<?php

namespace App\Services\Backup\Restore;

use RuntimeException;

/** A restauração não pode seguir (erro do `pg_restore` ou banco restaurado que não serve). A mensagem é segura para a tela. */
final class RestoreRejected extends RuntimeException {}
