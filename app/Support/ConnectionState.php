<?php

namespace App\Support;

use App\Enums\ChannelStatus;
use App\Models\Channel;
use Illuminate\Database\Eloquent\Collection;

/** O estado vem do banco, não de um evento: quem abre a tela depois da queda também vê a faixa (o Reverb não reenvia). */
final class ConnectionState
{
    /** @return Collection<int, Channel> canais ativos que não estão conectados */
    public static function down(): Collection
    {
        return Channel::query()->where('active', true)->where('status', '<>', ChannelStatus::WORKING->value)->orderBy('id')->get();
    }
}
