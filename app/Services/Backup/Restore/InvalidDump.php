<?php

namespace App\Services\Backup\Restore;

use RuntimeException;

/** O arquivo não serve para restaurar. A mensagem é segura para mostrar na tela: nunca leva senha nem linha de comando. */
final class InvalidDump extends RuntimeException {}
