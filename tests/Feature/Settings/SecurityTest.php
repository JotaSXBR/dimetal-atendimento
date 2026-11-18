<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);
        Features::passkeys([
            'confirmPassword' => true,
        ]);
    }

    public function test_security_settings_page_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'));

        $response->assertOk();

        $response->assertSee('Chaves de acesso');
        $response->assertSee('Nenhuma chave de acesso ainda');
        $response->assertSee('Verificação em duas etapas');
        $response->assertSee('Ativar 2FA');
    }

    public function test_security_settings_page_requires_password_confirmation_when_enabled(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('security.edit'));

        $response->assertRedirect(route('password.confirm'));
    }

    public function test_security_settings_page_renders_without_two_factor_when_feature_is_disabled(): void
    {
        config(['fortify.features' => []]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertSee('Senha atual')
            ->assertDontSee('Gerencie as suas chaves de acesso para entrar sem senha')
            ->assertDontSee('Adicione uma chave de acesso para entrar sem senha')
            ->assertDontSee('Verificação em duas etapas');
    }

    public function test_two_factor_authentication_disabled_when_confirmation_abandoned_between_requests(): void
    {
        $user = User::factory()->create();

        $user->forceFill([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->actingAs($user);

        $component = Livewire::test('pages::settings.security');

        $component->assertSet('twoFactorEnabled', false);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ]);
    }

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword');

        $response->assertHasNoErrors();

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.security')
            ->set('current_password', 'wrong-password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword');

        $response->assertHasErrors(['current_password']);
    }

    public function test_seguranca_no_painel_do_desenho(): void
    {
        $user = User::factory()->create();
        $xpath = $this->xpath($this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk()->getContent());

        $this->assertSame(1, $xpath->query($this->panel('Atualizar senha').'//form//*[@data-test="update-password-button"]')->length);
        $this->assertSame(1, $xpath->query($this->panel('Verificação em duas etapas').'[contains(., "Ativar 2FA")]')->length);
        $this->assertSame(1, $xpath->query($this->panel('Chaves de acesso').'[contains(., "Nenhuma chave de acesso ainda")]')->length);
    }

    /** O Flux pinta zinc nos próprios controles; a regra vale para o markup nosso da aba. */
    public function test_seguranca_sem_zinc_cru_no_markup_da_aba(): void
    {
        foreach ([
            'pages/settings/⚡security.blade.php',
            'pages/settings/⚡two-factor-setup-modal.blade.php',
            'pages/settings/two-factor/⚡recovery-codes.blade.php',
            'components/passkey-registration.blade.php',
        ] as $file) {
            $this->assertSame(0, substr_count((string) file_get_contents(resource_path('views/'.$file)), 'zinc-'), $file);
        }
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($dom);
    }

    private function panel(string $title): string
    {
        return '//*[@data-panel][./*[@data-panel-header]//h2[normalize-space()="'.$title.'"]]';
    }
}
