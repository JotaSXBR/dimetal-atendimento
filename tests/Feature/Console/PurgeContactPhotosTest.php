<?php

namespace Tests\Feature\Console;

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeContactPhotosTest extends TestCase
{
    use RefreshDatabase;

    private const CDN = 'https://pps.whatsapp.net/v/t61/foto.jpg?sig=abc';

    public function test_purga_zera_url_e_checagem_so_com_mais_de_90_dias(): void
    {
        $old = Contact::factory()->create(['avatar_url' => self::CDN, 'avatar_checked_at' => now()->subDays(91)]);
        $recent = Contact::factory()->create(['avatar_url' => self::CDN, 'avatar_checked_at' => now()->subDays(89)]);

        $this->artisan('app:purge-media')->expectsOutputToContain('Fotos de contato expiradas: 1')->assertSuccessful();

        $this->assertNull($old->fresh()?->avatar_url);
        $this->assertNull($old->fresh()?->avatar_checked_at);
        $this->assertSame(self::CDN, $recent->fresh()?->avatar_url);
    }

    public function test_contato_renovado_nao_expira(): void
    {
        $contact = Contact::factory()->create(['avatar_url' => self::CDN, 'avatar_checked_at' => now()->subDays(91)]);
        $contact->forceFill(['avatar_checked_at' => now()])->save();

        $this->artisan('app:purge-media')->assertSuccessful();

        $this->assertSame(self::CDN, $contact->fresh()?->avatar_url);
    }
}
