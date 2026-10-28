<?php

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wa_id' => '55'.fake()->unique()->numerify('###########').'@c.us',
            'lid' => null,
            'name' => fake()->name(),
            'nickname' => null,
        ];
    }
}
