<?php

namespace App\Actions\Channels;

use App\Enums\AuditAction;
use App\Models\Channel;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\Validator;

/**
 * Cria o canal a partir do nome da sessão do WAHA. Usada pela tela de Canais e pelo assistente de primeiro acesso; a recusa
 * sai como `ValidationException` no campo `sessionName`, o mesmo das telas.
 */
final class CreateChannel
{
    public function __invoke(string $session, ?User $by): Channel
    {
        Validator::make(['sessionName' => $session], [
            'sessionName' => ['required', 'regex:/^[A-Za-z0-9_-]{1,60}$/', 'unique:channels,waha_session'],
        ], [
            'sessionName.regex' => 'Use só letras, números, hífen e sublinhado.',
            'sessionName.unique' => 'Já existe um canal com essa sessão.',
        ])->validate();

        $channel = Channel::create(['name' => $session, 'waha_session' => $session]);
        Auditor::record(AuditAction::ChannelCreated, $by, $session);

        return $channel;
    }
}
