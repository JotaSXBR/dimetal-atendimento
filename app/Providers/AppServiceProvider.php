<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Waha\WebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
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
        $this->configureDefaults();
        $this->configureGates();
        $this->configureRateLimiting();
    }

    protected function configureRateLimiting(): void
    {
        // Todo o tráfego do WAHA chega do mesmo IP interno e vem em rajada (importação, recuperação, presença): um teto por IP dava 429
        // em evento legítimo e perdia mensagem. Só a assinatura inválida conta; quem tem a assinatura certa nunca é limitado.
        // Custo: um WAHA descontrolado não tem freio aqui (a fila absorve).
        RateLimiter::for('webhook', fn (Request $request) => WebhookSignature::valid($request)
            ? Limit::none()
            : Limit::perMinute(config()->integer('security.webhook_invalid_per_minute'))->by('webhook-invalid:'.$request->ip()));
    }

    protected function configureGates(): void
    {
        Gate::define('admin', fn (User $user): bool => $user->isAdmin());
        Gate::define('supervisor', fn (User $user): bool => $user->canSeeAllSectors());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
