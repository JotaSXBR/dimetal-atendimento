<?php

namespace Tests\Feature\Services;

use App\Models\Channel;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaContacts;
use App\Services\Waha\WahaRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WahaContactsPhotoTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '5516997524950@c.us';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
    }

    private function waha(): WahaContacts
    {
        return new WahaContacts(new WahaClient(Channel::factory()->create(['waha_session' => 'main'])));
    }

    public function test_devolve_a_url_pedindo_pelo_contato_e_pela_sessao_sem_refresh(): void
    {
        Http::fake(['*/api/contacts/profile-picture*' => Http::response(['profilePictureURL' => 'https://pps.whatsapp.net/v/foto.jpg?sig=1'])]);

        $this->assertSame('https://pps.whatsapp.net/v/foto.jpg?sig=1', $this->waha()->profilePictureUrl(self::PHONE));

        Http::assertSent(fn ($request): bool => $request['contactId'] === self::PHONE
            && $request['session'] === 'main'
            && ! isset($request['refresh'])
            && $request->hasHeader('X-Api-Key', 'test-key'));
    }

    /** @return array<string, array{int, mixed}> */
    public static function withoutPhoto(): array
    {
        return [
            'null' => [200, ['profilePictureURL' => null]],
            'campo ausente' => [200, []],
            'vazio' => [200, ['profilePictureURL' => '']],
            'não encontrado' => [404, ['message' => 'not found']],
        ];
    }

    #[DataProvider('withoutPhoto')]
    public function test_sem_foto_vira_null(int $status, mixed $body): void
    {
        Http::fake(['*/api/contacts/profile-picture*' => Http::response($body, $status)]);

        $this->assertNull($this->waha()->profilePictureUrl(self::PHONE));
    }

    #[DataProvider('transientStatuses')]
    public function test_limite_e_erro_do_servidor_lancam_excecao(int $status): void
    {
        Http::fake(['*/api/contacts/profile-picture*' => Http::response(['message' => 'x'], $status)]);

        $this->expectException(WahaRequestException::class);

        $this->waha()->profilePictureUrl(self::PHONE);
    }

    /** @return array<string, array{int}> */
    public static function transientStatuses(): array
    {
        return ['429' => [429], '500' => [500], '503' => [503]];
    }
}
