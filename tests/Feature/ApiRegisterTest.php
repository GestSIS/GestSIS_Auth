<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class ApiRegisterTest extends TestCase
{
    /**
     * Registering with an email that already exists must return a clean,
     * field-keyed validation error (HTTP 401 with `error.email`) rather than
     * surfacing the database unique-constraint violation as a 500. The frontend
     * (PageRegister.vue) relies on `error.email` being set to show its message.
     */
    public function testRegisterWithExistingEmailReturnsEmailError(): void
    {
        $existing = User::factory()->create(['email' => 'duplicate@example.com']);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Someone Else',
            'email' => $existing->email,
            'password' => 'a-very-long-password',
            'password_confirmation' => 'a-very-long-password',
        ]);

        $response->assertStatus(401);
        $response->assertJsonStructure(['error' => ['email']]);

        // No second user should have been created for that email.
        $this->assertSame(1, User::where('email', $existing->email)->count());
    }
}
