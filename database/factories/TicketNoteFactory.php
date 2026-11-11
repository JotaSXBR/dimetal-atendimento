<?php

namespace Database\Factories;

use App\Enums\NoteOrigin;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketNote>
 */
class TicketNoteFactory extends Factory
{
    /** Nota escrita pela automação: sem autor. */
    public function bot(): static
    {
        return $this->state(['user_id' => null, 'origin' => NoteOrigin::BOT]);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'user_id' => User::factory(),
            'origin' => NoteOrigin::EQUIPE,
            'body' => fake()->sentence(),
        ];
    }
}
