<?php

namespace App\Actions\Sectors;

use RuntimeException;

/** O setor ainda é usado e não pode ser apagado. A mensagem já serve para mostrar na tela. */
class SectorInUse extends RuntimeException {}
