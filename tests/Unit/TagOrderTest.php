<?php

namespace Tests\Unit;

use App\Models\Tag;
use Tests\TestCase;

class TagOrderTest extends TestCase
{
    public function test_etiquetas_ordenam_sem_diferenciar_acento_nem_caixa(): void
    {
        $tags = collect(['Ágil', 'beta', 'Aéreo', 'Zebra', 'álcool'])->map(fn (string $name): Tag => new Tag(['name' => $name]));

        $this->assertSame(['Aéreo', 'Ágil', 'álcool', 'beta', 'Zebra'], Tag::sortedByName($tags)->map(fn (Tag $t): string => $t->name)->all());
    }
}
