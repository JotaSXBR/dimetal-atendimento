<?php

namespace App\Services\Media;

use RuntimeException;

/** Imagem recusada (tipo, tamanho ou conteúdo inválido). A mensagem já serve para mostrar ao usuário. */
class InvalidImage extends RuntimeException {}
