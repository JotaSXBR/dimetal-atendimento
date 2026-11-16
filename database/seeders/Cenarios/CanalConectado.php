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
        return Channel::query()->where('status', ChannelStatus::WORKING)->first() ?? Channel::factory()->connected()->create(['phone' => '5511999990000']);
    }
}
