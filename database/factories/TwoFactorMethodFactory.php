<?php

namespace Database\Factories;

use App\Models\TwoFactorMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use OTPHP\TOTP;

/**
 * @extends Factory<TwoFactorMethod>
 */
class TwoFactorMethodFactory extends Factory
{
    /**
     * Par défaut : une application TOTP confirmée.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => TwoFactorMethod::TYPE_TOTP,
            'name' => "Application d'authentification",
            'secret' => TOTP::generate()->getSecret(),
            'confirmed_at' => now(),
        ];
    }

    public function totp(?string $secret = null): static
    {
        return $this->state(fn () => [
            'type' => TwoFactorMethod::TYPE_TOTP,
            'name' => "Application d'authentification",
            'secret' => $secret ?? TOTP::generate()->getSecret(),
        ]);
    }

    public function webauthn(): static
    {
        return $this->state(fn () => [
            'type' => TwoFactorMethod::TYPE_WEBAUTHN,
            'name' => 'Clé ' . fake()->word(),
            'secret' => null,
            'credential_id' => base64_encode(random_bytes(16)),
            'public_key' => base64_encode(random_bytes(32)),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
        ]);
    }

    /**
     * Enrôlement commencé mais jamais confirmé : ne doit jamais être accepté
     * comme second facteur.
     */
    public function unconfirmed(): static
    {
        return $this->state(fn () => ['confirmed_at' => null]);
    }
}
