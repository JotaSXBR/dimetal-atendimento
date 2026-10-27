<?php

namespace Database\Factories;

use App\Enums\ChannelStatus;
use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'WhatsApp',
            'waha_session' => 'main',
            'phone' => null,
            'status' => ChannelStatus::STOPPED,
            'last_event_at' => null,
            'backfill_days' => 0,
            'backfill_last_run_at' => null,
            'active' => true,
        ];
    }

    public function connected(): static
    {
        return $this->state(fn () => ['status' => ChannelStatus::WORKING]);
    }
}
