<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Só entra quem existe, está ativo e acertou a senha.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            // Campo que não é texto (um array forjado) não derruba o login com 500: conta como credencial inválida.
            $typedEmail = $request->input(Fortify::username());
            $typedPassword = $request->input('password');
            $email = is_string($typedEmail) ? $typedEmail : '';

            /** @var User|null $user */
            $user = User::query()->where(Fortify::username(), $email)->first();

            if ($user === null || ! $user->active) {
                return null;
            }

            return Hash::check(is_string($typedPassword) ? $typedPassword : '', $user->password) ? $user : null;
        });
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $typed = $request->input(Fortify::username());
            $email = is_string($typed) ? Str::transliterate(trim(Str::lower($typed))) : '';

            $limits = [Limit::perMinute(5)->by($email.'|'.$request->ip())];

            // Por e-mail, de qualquer IP: sem isto, 100 IPs diferentes testam senhas de uma conta sem freio. Custo: quem sabe o e-mail
            // de alguém consegue travar o login dele por até 1 hora errando senhas. Sem e-mail não há conta a proteger (e todos os
            // pedidos sem e-mail dividiriam a mesma chave).
            if ($email !== '') {
                $limits[] = Limit::perHour(config()->integer('security.login_per_hour_per_email'))->by('login-email:'.$email);
            }

            return $limits;
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->string('credential.id')->toString();

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
