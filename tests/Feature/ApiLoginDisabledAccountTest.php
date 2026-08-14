<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiLoginDisabledAccountTest extends TestCase
{
    public function testDisabledAccountCannotLoginAndGetsGenericError(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('a-very-long-password'),
            'disabled_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'Les identifiants fournis sont incorrects');
        $this->assertArrayNotHasKey('accessToken', $response->json());
    }
}
