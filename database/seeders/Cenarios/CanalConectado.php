<?php

namespace Database\Seeders\Cenarios;

use App\Enums\ChannelStatus;
use App\Models\Channel;
use Illuminate\Database\Seeder;

class CanalConectado extends Seeder
{
    public function run(): void
    {
        self::canal();
    }

    public static function canal(): Channel
    {
        // Sessão `main` é única: um canal parado vira conectado em vez de duplicar.
        return Channel::query()->updateOrCreate(['waha_session' => 'main'], ['name' => 'WhatsApp', 'phone' => '5511999990000', 'status' => ChannelStatus::WORKING, 'active' => true]);
    }
}
