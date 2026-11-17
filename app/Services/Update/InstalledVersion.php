<?php

namespace App\Services\Update;

final class InstalledVersion
{
    public function current(): string
    {
        $env = config('fluxie.version');

        return is_string($env) && $env !== '' ? $env : (string) config('fluxie.build');
    }

    public function release(): ?Version
    {
        return Version::parse($this->current());
    }
}
