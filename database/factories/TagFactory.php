<?php

namespace Database\Factories;

use App\Enums\TagColor;
use App\Enums\TagScope;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tag> */
class TagFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => fake()->unique()->word().fake()->unique()->numerify('##'), 'scope' => TagScope::CONVERSA, 'color' => TagColor::default()];
    }

    public function contato(): static
    {
        return $this->state(['scope' => TagScope::CONTATO]);
    }
}
