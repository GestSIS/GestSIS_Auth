<?php

namespace Tests\Feature;

use App\Models\Domaine;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SelfRegistrationGateTest extends TestCase
{
    public function testRegistrationWithoutTokenSucceedsForSisDomaineWhereFlagIsBackfilledTrue(): void
    {
        Http::fake(['*/email-validate*' => Http::response(['data' => true], 200)]);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Someone',
            'email' => 'new@example.com',
            'password' => 'Str0ngP@ssw0rd!',
            'password_confirmation' => 'Str0ngP@ssw0rd!',
            'domaine_code' => 'sis',
        ]);

        $response->assertStatus(200);
    }

    public function testRegistrationWithoutTokenIsRejectedWhenSelfRegistrationDisabled(): void
    {
        Domaine::where('code', 'sis')->update(['self_registration_enabled' => false]);
        Http::fake(['*/email-validate*' => Http::response(['data' => true], 200)]);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Someone',
            'email' => 'new2@example.com',
            'password' => 'Str0ngP@ssw0rd!',
            'password_confirmation' => 'Str0ngP@ssw0rd!',
            'domaine_code' => 'sis',
        ]);

        $response->assertStatus(422);
        Http::assertNothingSent();
    }
}
