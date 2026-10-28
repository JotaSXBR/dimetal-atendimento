<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateUser extends Command
{
    protected $signature = 'app:create-user {email} {name} {--role=atendente}';

    protected $description = 'Cria ou atualiza um usuário interno';

    public function handle(): int
    {
        $role = UserRole::tryFrom((string) $this->option('role'));

        if ($role === null) {
            $this->error('Papel inválido. Use admin, supervisor ou atendente.');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');

        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email']]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first('email'));

            return self::FAILURE;
        }

        $secret = $this->secret('Senha');
        $password = is_string($secret) ? $secret : '';

        if (strlen($password) < 8) {
            $this->error('A senha precisa ter ao menos 8 caracteres.');

            return self::FAILURE;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) $this->argument('name'),
                'password' => $password,
                'role' => $role,
                'active' => true,
                'email_verified_at' => now(),
            ],
        );

        $this->info("Usuário {$email} salvo como {$role->label()}.");

        return self::SUCCESS;
    }
}
