<?php

namespace Database\Factories;

use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Workflow> */
class WorkflowFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'draft' => ['nodes' => [], 'edges' => []],
        ];
    }
}
