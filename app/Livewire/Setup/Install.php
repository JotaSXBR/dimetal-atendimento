<?php

namespace App\Livewire\Setup;

use App\Concerns\PasswordValidationRules;
use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Setup\SetupState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Primeiro acesso: quem tem o código da instalação (`SETUP_CODE`) cria o administrador. */
#[Title('Instalação')]
#[Layout('layouts::auth')]
class Install extends Component
{
    use PasswordValidationRules;

    public string $code = '';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        abort_if(SetupState::concluded(), 404);

        if (SetupState::hasAdmin()) {
            $this->redirectRoute('login');
        }
    }

    public function save(): void
    {
        abort_if(SetupState::concluded(), 404);

        $configured = config()->string('setup.code');
        $key = 'setup-code:'.request()->ip();

        if ($configured === '') {
            return;
        }

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('code', 'Muitas tentativas. Tente de novo em '.RateLimiter::availableIn($key).' segundos.');

            return;
        }

        if (! hash_equals($configured, $this->code)) {
            RateLimiter::hit($key, 60);
            $this->addError('code', 'Código inválido.');

            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => $this->passwordRules(),
        ]);

        $user = DB::transaction(function (): ?User {
            DB::select("select pg_advisory_xact_lock(hashtext('setup.admin'))");

            if (SetupState::hasAdmin()) {
                return null;
            }

            $user = (new User)->forceFill([
                'name' => $this->name,
                'email' => $this->email,
                'password' => $this->password,
                'role' => UserRole::ADMIN,
                'active' => true,
                'email_verified_at' => now(),
            ]);
            $user->save();

            return $user;
        });

        if ($user === null) {
            $this->addError('code', 'A instalação já tem um administrador.');

            return;
        }

        Auditor::record(AuditAction::UserCreated, $user, $user->email, ['role' => 'admin'], 'Instalação');

        Auth::login($user);
        session()->regenerate();

        $this->redirectRoute('primeiros-passos');
    }

    public function render(): View
    {
        return view('livewire.setup.install', ['configured' => config()->string('setup.code') !== '']);
    }
}
