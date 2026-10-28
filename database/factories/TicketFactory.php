<?php

namespace Database\Factories;

use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'channel_id' => Channel::factory(),
            'status' => TicketStatus::AGUARDANDO,
            'opened_at' => now(),
        ];
    }
}
