<?php

namespace Database\Factories;

use App\Models\Domaine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Organisation>
 */
class OrganisationFactory extends Factory
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
            'org_key' => fake()->unique()->slug(2),
            'mobile' => false,
            'domaine_id' => Domaine::where('code', 'sis')->value('id'),
        ];
    }
}
