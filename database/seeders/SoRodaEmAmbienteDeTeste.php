<?php

namespace Database\Seeders;

use RuntimeException;

/** Guarda dos seeders de conferência visual: criam usuário com senha conhecida, então recusam fora de local/testing. */
trait SoRodaEmAmbienteDeTeste
{
    protected function exigirAmbienteDeTeste(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(class_basename($this).' só roda nos ambientes local e testing (atual: '.app()->environment().').');
        }
    }
}
