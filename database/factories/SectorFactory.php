<?php

namespace Database\Factories;

use App\Enums\SectorStatus;
use App\Models\Sector;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sector>
 */
class SectorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word().' '.fake()->unique()->numerify('##'),
            'menu_order' => 0,
            'status' => SectorStatus::ATIVO,
        ];
    }

    public function foraDoMenu(): static
    {
        return $this->state(fn () => ['status' => SectorStatus::FORA_DO_MENU]);
    }

    public function desativado(): static
    {
        return $this->state(fn () => ['status' => SectorStatus::DESATIVADO]);
    }
}
