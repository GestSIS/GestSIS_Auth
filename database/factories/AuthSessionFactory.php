<?php

namespace Database\Factories;

use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthSession>
 */
class AuthSessionFactory extends Factory
{
    /**
     * Session « se souvenir de moi » ouverte à l'instant.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'refresh_generation' => 0,
            'remember' => true,
            'started_at' => now(),
            'last_refreshed_at' => now(),
            'idle_expires_at' => now()->addDays(30),
            'ip_address' => '10.0.0.1',
            'user_agent' => 'PHPUnit',
        ];
    }

    public function startedDaysAgo(int $days): static
    {
        return $this->state(fn () => ['started_at' => now()->subDays($days)]);
    }
}
