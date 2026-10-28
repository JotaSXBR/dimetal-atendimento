<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SetUserCanTransfer extends Command
{
    protected $signature = 'app:user-can-transfer {email} {state : on ou off}';

    protected $description = 'Liga ou desliga a opção "pode transferir" de um usuário (transferir e tomar tickets)';

    public function handle(): int
    {
        $state = strtolower((string) $this->argument('state'));

        if (! in_array($state, ['on', 'off'], true)) {
            $this->error('Use on ou off.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if ($user === null) {
            $this->error('Usuário não encontrado.');

            return self::FAILURE;
        }

        $user->forceFill(['can_transfer' => $state === 'on'])->save();

        $this->info("{$user->name}: pode transferir = {$state}");

        return self::SUCCESS;
    }
}
