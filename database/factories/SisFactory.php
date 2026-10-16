<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Sis>
 */
class SisFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nom' => fake()->unique()->company(),
            'abreviation' => fake()->unique()->lexify('???'),
            'api_key' => fake()->unique()->slug(2),
            'mobile' => false,
        ];
    }
}
