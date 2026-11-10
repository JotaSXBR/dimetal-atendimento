<?php

namespace Tests\Feature\Components;

use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

class EmptyStateScreensTest extends TestCase
{
    public function test_contatos_vazio_usa_estado_vazio_e_esqueleto_na_busca(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('contacts.index')
            ->assertSeeHtml('data-empty-state')
            ->assertSee('Nenhum contato encontrado.')
            ->assertSeeHtml('data-skeleton')
            ->assertSeeHtml('wire:target="search"');
    }

    public function test_historico_vazio_usa_estado_vazio_e_esqueleto_nos_filtros(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('history.index')
            ->assertSeeHtml('data-empty-state')
            ->assertSee('Nenhum ticket encerrado com esses filtros.')
            ->assertSeeHtml('data-skeleton')
            ->assertSeeHtml('wire:target="q,from,to,sectorId,outcome,userId"');
    }
}
