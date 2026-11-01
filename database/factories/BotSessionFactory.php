<?php

namespace Database\Factories;

use App\Models\BotSession;
use App\Models\Ticket;
use App\Models\WorkflowVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BotSession> */
class BotSessionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'workflow_version_id' => WorkflowVersion::factory(),
            'vars' => [],
            'state' => BotSession::ACTIVE,
        ];
    }
}
