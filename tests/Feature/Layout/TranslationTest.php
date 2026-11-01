<?php

namespace Tests\Feature\Layout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslationTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function usedKeys(): array
    {
        $keys = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));
        $views = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ([$files, $views] as $iterator) {
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                preg_match_all('/__[(]\s*([\'"])(.*?)\1/s', (string) file_get_contents($file->getPathname()), $found);
                $keys = [...$keys, ...$found[2]];
            }
        }

        // Chaves de grupo (`auth.failed`) vivem nos arquivos PHP de idioma, não no JSON.
        return array_values(array_unique(array_filter($keys, fn (string $key): bool => preg_match('/^[a-z_]+[.][a-z_.]+$/', $key) !== 1)));
    }

    public function test_toda_chave_usada_em_codigo_tem_traducao(): void
    {
        $json = json_decode((string) file_get_contents(base_path('lang/pt_BR.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($this->usedKeys());
        $this->assertSame([], array_values(array_filter($this->usedKeys(), fn (string $key): bool => ! isset($json[$key]))));
    }

    public function test_login_com_senha_errada_responde_em_portugues(): void
    {
        User::factory()->create(['email' => 'ana@example.com']);

        $response = $this->from('/login')->post('/login', ['email' => 'ana@example.com', 'password' => 'senha-errada']);

        $response->assertSessionHasErrors('email');
        $message = (string) session('errors')->first('email');
        $this->assertStringNotContainsString('credentials', $message);
        $this->assertSame('Essas credenciais não conferem com os nossos registros.', $message);
    }

    public function test_validacao_em_portugues(): void
    {
        $this->from('/login')->post('/login', [])->assertSessionHasErrors(['email' => 'O campo e-mail é obrigatório.']);
    }

    public function test_telas_publicas_sem_ingles(): void
    {
        foreach (['/login'] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            foreach (['Forgot password', 'Email address', 'Log in', 'Reset password', 'Remember me', 'Email password reset link', 'Or, return to'] as $english) {
                $this->assertStringNotContainsString($english, $html, $url.': '.$english);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $lines
     * @return list<string>
     */
    private function flatKeys(array $lines, string $prefix = ''): array
    {
        $keys = [];

        foreach ($lines as $key => $value) {
            if ($prefix === '' && in_array($key, ['attributes', 'custom'], true)) {
                continue;
            }

            $keys = [...$keys, ...(is_array($value) ? $this->flatKeys($value, $prefix.$key.'.') : [$prefix.$key])];
        }

        return $keys;
    }

    public function test_validation_cobre_todas_as_chaves_do_framework(): void
    {
        $framework = $this->flatKeys(require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'));
        $ptBr = $this->flatKeys(require lang_path('pt_BR/validation.php'));

        $this->assertNotEmpty($framework);
        $this->assertSame([], array_values(array_diff($framework, $ptBr)));
    }
}
