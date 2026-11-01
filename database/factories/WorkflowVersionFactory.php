<?php

namespace Database\Factories;

use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkflowVersion> */
class WorkflowVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'number' => 1,
            'graph' => ['nodes' => [], 'edges' => []],
            'published_at' => now(),
        ];
    }
}
