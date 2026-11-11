<?php

namespace Tests\Feature\Reports;

use App\Enums\UserRole;
use App\Livewire\Reports\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsMarkupTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_lista_de_numeros_e_uma_dl_valida(): void
    {
        $html = Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Index::class)->html();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $this->assertGreaterThan(0, $xpath->query('//dl/div')->length);
        // Dentro do `<div>` de um `<dl>` só entram `<dt>` e `<dd>` (axe: definition-list).
        $this->assertSame(0, $xpath->query('//dl/div/*[not(self::dt or self::dd)]')->length);
    }
}
