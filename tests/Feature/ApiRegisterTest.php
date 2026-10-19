<?php

namespace Tests\Feature;

use App\Models\Domaine;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiRegisterTest extends TestCase
{
    /**
     * Registering with an email that already exists must return a clean,
     * field-keyed validation error (HTTP 422 with `errors.email`) rather than
     * surfacing the database unique-constraint violation as a 500. The frontend
     * (PageRegister.vue) relies on `errors.email` being set to show its message.
     *
     * Registration without a token checks the email against GestSIS_API before
     * reaching the duplicate-email check, so this test fakes that call to let the
     * flow reach the duplicate-email path specifically.
     */
    public function testRegisterWithExistingEmailReturnsEmailError(): void
    {
        Http::fake(['*/api/v2/email-validate*' => Http::response(['data' => ['hs' => 99]])]);
        $existing = User::factory()->create(['email' => 'duplicate@example.com']);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Someone Else',
            'email' => $existing->email,
            'password' => 'a-very-long-password',
            'password_confirmation' => 'a-very-long-password',
            'domaine_code' => 'sis',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['email']]);

        // No second user should have been created for that email.
        $this->assertSame(1, User::where('email', $existing->email)->count());
    }

    public function testRegisterReturnsFrenchPasswordErrors(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'password' => 'court',
            'password_confirmation' => 'autre',
            'domaine_code' => 'sis',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.password', [
            'Le mot de passe doit contenir au moins 12 caractères.',
            'La confirmation du mot de passe ne correspond pas.',
        ]);
    }

    public function testRegisterRejectsAnUnknownDomaineCode(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'password' => 'a-very-long-password',
            'password_confirmation' => 'a-very-long-password',
            'domaine_code' => 'does-not-exist',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['domaine_code']);
    }

    public function testRegisterRejectsWithoutAnyHttpCallWhenSelfRegistrationIsDisabled(): void
    {
        Domaine::where('code', 'sis')->update(['self_registration_enabled' => false]);
        Http::fake();

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'password' => 'a-very-long-password',
            'password_confirmation' => 'a-very-long-password',
            'domaine_code' => 'sis',
        ]);

        $response->assertStatus(422);
        Http::assertNothingSent();
    }

    public function testRegisterRejectsAndReportsWhenEmailValidateFails(): void
    {
        Http::fake(['*/api/v2/email-validate*' => Http::response(['error' => 'boom'], 500)]);
        $this->mock(ExceptionHandler::class, fn($mock) => $mock->shouldReceive('report')->once());

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'password' => 'a-very-long-password',
            'password_confirmation' => 'a-very-long-password',
            'domaine_code' => 'sis',
        ]);

        $response->assertStatus(422);
    }
}
