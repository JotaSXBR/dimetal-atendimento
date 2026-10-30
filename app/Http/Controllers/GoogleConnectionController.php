<?php

namespace App\Http\Controllers;

use App\Services\Google\GoogleAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as OAuthUser;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/** OAuth com o Google: autoriza o acesso aos contatos da conta da empresa (só administrador; o middleware `can:admin` das rotas protege). */
class GoogleConnectionController extends Controller
{
    private const SCOPES = ['https://www.googleapis.com/auth/contacts', 'email'];

    public function redirect(): SymfonyRedirect
    {
        // `offline` + `consent` fazem o Google devolver o refresh token também ao reconectar.
        return $this->provider()->scopes(self::SCOPES)->with(['access_type' => 'offline', 'prompt' => 'consent'])->redirect();
    }

    public function callback(Request $request, GoogleAccount $account): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->back('O acesso não foi autorizado no Google.');
        }

        try {
            $user = $this->provider()->user();

            if (! $user instanceof OAuthUser || $user->getEmail() === null) {
                throw new RuntimeException('Resposta do Google sem e-mail.');
            }
        } catch (Throwable) {
            return $this->back('Não foi possível concluir a conexão com o Google. Tente de novo.');
        }

        $account->connect((string) $user->getEmail(), $user->token, $user->refreshToken, $user->expiresIn);

        return redirect()->route('configuracoes.google');
    }

    private function provider(): GoogleProvider
    {
        $provider = Socialite::driver('google');

        return $provider instanceof GoogleProvider ? $provider : throw new RuntimeException('Provedor Google inesperado.');
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('configuracoes.google')->with('google_error', $message);
    }
}
