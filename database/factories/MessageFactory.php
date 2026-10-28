<?php

namespace Database\Factories;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'channel_id' => fn (array $attributes) => Ticket::query()->whereKey($attributes['ticket_id'])->firstOrFail()->channel_id,
            'contact_id' => fn (array $attributes) => Ticket::query()->whereKey($attributes['ticket_id'])->firstOrFail()->contact_id,
            'wa_message_id' => strtoupper(fake()->unique()->bothify('3EB0????????????')),
            'direction' => MessageDirection::IN,
            'type' => 'text',
            'body' => fake()->sentence(),
            'origin' => MessageOrigin::CONTATO,
            'sent_at' => now(),
        ];
    }
}
