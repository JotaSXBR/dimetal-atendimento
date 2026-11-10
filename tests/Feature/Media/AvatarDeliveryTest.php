<?php

namespace Tests\Feature\Media;

use App\Jobs\SyncContactPhotoJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Media\AvatarImage;
use App\Settings\CompanySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config()->string('media.disk'));
    }

    private function png(int $r = 200): string
    {
        $image = imagecreatetruecolor(40, 40);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $r, 30, 30));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function webp(int $r = 200): string
    {
        return (new AvatarImage)->fromBinary($this->png($r), 64);
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(40, 40);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    public function test_foto_exige_login(): void
    {
        $user = User::factory()->create();
        $user->setAvatar($this->webp());

        $this->get(route('avatar', ['users', $user->id]))->assertRedirect(route('login'));
    }

    public function test_logo_e_publico(): void
    {
        CompanySettings::setLogo($this->webp());

        $this->get(route('logo'))->assertOk()->assertHeader('Content-Type', 'image/webp');
    }

    public function test_logo_ausente_devolve_404(): void
    {
        $this->get(route('logo'))->assertNotFound();
    }

    public function test_foto_com_cabecalhos_de_cache_e_etag(): void
    {
        $viewer = User::factory()->create();
        $user = User::factory()->create();
        $user->setAvatar($this->webp());

        $first = $this->actingAs($viewer)->get($user->avatarUrl());

        $first->assertOk();
        $cache = (string) $first->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('max-age=31536000', $cache);
        $this->assertStringContainsString('immutable', $cache);

        $etag = (string) $first->headers->get('ETag');
        $this->assertNotSame('', $etag);
        $this->actingAs($viewer)->get($user->avatarUrl(), ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_url_com_versao_velha_serve_a_imagem_atual(): void
    {
        $viewer = User::factory()->create();
        $user = User::factory()->create();
        $user->setAvatar($this->webp());

        $this->actingAs($viewer)->get(route('avatar', ['users', $user->id, 'v' => 'antiga']))->assertOk();
    }

    public function test_sem_foto_devolve_404(): void
    {
        $viewer = User::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($viewer)->get(route('avatar', ['users', $user->id]))->assertNotFound();
        $this->actingAs($viewer)->get(route('avatar', ['contacts', Contact::factory()->create()->id]))->assertNotFound();
    }

    public function test_trocar_a_foto_apaga_o_objeto_antigo_e_muda_a_versao(): void
    {
        $user = User::factory()->create();
        $user->setAvatar($this->webp(10));
        $oldPath = (string) $user->avatar_path;
        $oldVersion = $user->avatar_version;

        $user->setAvatar($this->webp(240));

        Storage::disk(config()->string('media.disk'))->assertMissing($oldPath);
        Storage::disk(config()->string('media.disk'))->assertExists((string) $user->avatar_path);
        $this->assertNotSame($oldVersion, $user->avatar_version);
        $this->assertStringStartsWith('avatars/users/', (string) $user->avatar_path);
    }

    public function test_remover_a_foto_apaga_o_objeto_e_zera_as_colunas(): void
    {
        $user = User::factory()->create();
        $user->setAvatar($this->webp());
        $path = (string) $user->avatar_path;

        $user->clearAvatar();

        Storage::disk(config()->string('media.disk'))->assertMissing($path);
        $this->assertNull($user->fresh()?->avatar_path);
        $this->assertNull($user->avatarUrl());
    }

    public function test_tipo_invalido_na_rota_devolve_404(): void
    {
        $this->actingAs(User::factory()->create())->get('/avatares/grupos/1')->assertNotFound();
    }

    public function test_foto_de_contato_e_repassada_sem_gravar_no_disco(): void
    {
        $body = $this->jpeg();
        Http::fake(['https://pps.whatsapp.net/*' => Http::response($body, 200, ['Content-Type' => 'image/jpeg'])]);
        $contact = Contact::factory()->create(['avatar_url' => 'https://pps.whatsapp.net/v/t61/foto.jpg?sig=1', 'avatar_checked_at' => now()]);

        $response = $this->actingAs(User::factory()->create())->get($contact->avatarUrl());

        $response->assertOk();
        $this->assertSame($body, $response->getContent());
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=86400', (string) $response->headers->get('Cache-Control'));
        $this->assertSame([], Storage::disk(config()->string('media.disk'))->allFiles());
    }

    public function test_foto_de_contato_com_cdn_403_devolve_404_e_enfileira_nova_busca(): void
    {
        Queue::fake();
        Http::fake(['https://pps.whatsapp.net/*' => Http::response('', 403)]);
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create(['avatar_url' => 'https://pps.whatsapp.net/v/t61/foto.jpg?sig=1']);
        Ticket::factory()->create(['contact_id' => $contact->id, 'channel_id' => $channel->id]);

        $this->actingAs(User::factory()->create())->get($contact->avatarUrl())->assertNotFound();

        Queue::assertPushed(SyncContactPhotoJob::class, fn (SyncContactPhotoJob $job): bool => $job->contactId === $contact->id && $job->channelId === $channel->id);
        // A URL vencida sai da tela de uma vez: nada de pedir a mesma imagem de novo a cada renderização.
        $this->assertNull($contact->fresh()?->avatar_url);
    }

    public function test_url_vencida_devolvida_de_novo_pelo_waha_nao_vira_laco_de_jobs(): void
    {
        Queue::fake();
        Http::fake(['https://pps.whatsapp.net/*' => Http::response('', 403)]);
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create(['avatar_url' => 'https://pps.whatsapp.net/v/t61/foto.jpg?sig=1']);
        Ticket::factory()->create(['contact_id' => $contact->id, 'channel_id' => $channel->id]);
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->get($contact->avatarUrl())->assertNotFound();
        // O WAHA guarda a URL por 24 h e devolve a mesma, já vencida: o job a grava de novo e a tela pede outra vez.
        Contact::query()->whereKey($contact->id)->update(['avatar_url' => 'https://pps.whatsapp.net/v/t61/foto.jpg?sig=1']);
        $this->actingAs($viewer)->get((string) $contact->fresh()?->avatarUrl())->assertNotFound();

        Queue::assertPushed(SyncContactPhotoJob::class, 1);
    }

    public function test_foto_de_contato_com_content_length_acima_do_limite_e_recusada(): void
    {
        Http::fake(['https://pps.whatsapp.net/*' => Http::response('pequeno', 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => '3000000'])]);
        $contact = Contact::factory()->create(['avatar_url' => 'https://pps.whatsapp.net/v/t61/enorme.jpg']);

        $this->actingAs(User::factory()->create())->get($contact->avatarUrl())->assertNotFound();
    }

    public function test_foto_de_contato_recusa_http_texto_e_arquivo_grande(): void
    {
        $viewer = User::factory()->create();

        $http = Contact::factory()->create(['avatar_url' => 'http://pps.whatsapp.net/foto.jpg']);
        $this->actingAs($viewer)->get($http->avatarUrl())->assertNotFound();
        Http::assertNothingSent();

        Http::fake(['https://pps.whatsapp.net/texto' => Http::response('nao sou imagem', 200, ['Content-Type' => 'text/html'])]);
        $text = Contact::factory()->create(['avatar_url' => 'https://pps.whatsapp.net/texto']);
        $this->actingAs($viewer)->get($text->avatarUrl())->assertNotFound();

        Http::fake(['https://pps.whatsapp.net/grande' => Http::response(str_repeat('a', 2_100_000), 200, ['Content-Type' => 'image/jpeg'])]);
        $big = Contact::factory()->create(['avatar_url' => 'https://pps.whatsapp.net/grande']);
        $this->actingAs($viewer)->get($big->avatarUrl())->assertNotFound();
    }

    public function test_304_sai_antes_de_abrir_o_objeto_no_disco(): void
    {
        $viewer = User::factory()->create();
        $user = User::factory()->create();
        $user->setAvatar($this->webp());
        $etag = (string) $this->actingAs($viewer)->get($user->avatarUrl())->headers->get('ETag');

        // Com o objeto fora do disco, só um 304 que nem toca o disco ainda responde.
        Storage::disk(config()->string('media.disk'))->delete((string) $user->avatar_path);

        $this->actingAs($viewer)->get($user->avatarUrl(), ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_etag_fraco_e_lista_de_etags_tambem_dao_304(): void
    {
        $viewer = User::factory()->create();
        $user = User::factory()->create();
        $user->setAvatar($this->webp());
        $etag = (string) $this->actingAs($viewer)->get($user->avatarUrl())->headers->get('ETag');

        foreach (['W/'.$etag, '"outro", W/'.$etag] as $header) {
            $this->actingAs($viewer)->get($user->avatarUrl(), ['If-None-Match' => $header])->assertStatus(304);
        }

        // `*` fica sem 304 de propósito: 200 com o corpo é resposta válida e não serve objeto que sumiu do disco.
        $this->actingAs($viewer)->get($user->avatarUrl(), ['If-None-Match' => '*'])->assertOk();
    }

    public function test_foto_sem_versao_gravada_nunca_fica_imutavel(): void
    {
        $viewer = User::factory()->create();
        $user = User::factory()->create();
        $user->setAvatar($this->webp());
        User::query()->whereKey($user->id)->update(['avatar_version' => null]);

        $cache = (string) $this->actingAs($viewer)->get(route('avatar', ['users', $user->id]))->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('no-cache', $cache);
        $this->assertStringNotContainsString('immutable', $cache);
    }

    public function test_logo_sem_versao_gravada_nunca_fica_imutavel(): void
    {
        CompanySettings::setLogo($this->webp());
        Setting::put('company.logo_version', null);

        $cache = (string) $this->get(route('logo'))->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('no-cache', $cache);
        $this->assertStringNotContainsString('immutable', $cache);
    }

    public function test_url_sem_versao_ou_com_versao_velha_revalida_em_vez_de_ficar_imutavel(): void
    {
        $viewer = User::factory()->create();
        $user = User::factory()->create();
        $user->setAvatar($this->webp());

        foreach ([route('avatar', ['users', $user->id]), route('avatar', ['users', $user->id, 'v' => 'antiga'])] as $url) {
            $cache = (string) $this->actingAs($viewer)->get($url)->assertOk()->headers->get('Cache-Control');

            $this->assertStringContainsString('no-cache', $cache);
            $this->assertStringNotContainsString('immutable', $cache);
        }

        $this->assertStringContainsString('immutable', (string) $this->actingAs($viewer)->get($user->avatarUrl())->headers->get('Cache-Control'));
    }

    public function test_logo_so_e_imutavel_com_a_versao_atual(): void
    {
        CompanySettings::setLogo($this->webp());

        $this->assertStringContainsString('immutable', (string) $this->get((string) CompanySettings::logoUrl())->headers->get('Cache-Control'));

        $semVersao = (string) $this->get(route('logo'))->assertOk()->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $semVersao);
        $this->assertStringNotContainsString('immutable', $semVersao);
    }
}
