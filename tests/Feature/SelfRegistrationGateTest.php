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

    /**
     * The core guarantee of the domaine-aware registration design: eligibility must be
     * decided by the ONE submitted domaine, never by aggregating across every configured
     * domaine. An email matching a sapeur in 'sis' must not let someone register through
     * 'eca' if 'eca' itself has no match -- and only eca's endpoint should even be called.
     */
    public function testRegistrationChecksOnlyTheSubmittedDomaineNotEveryConfiguredOne(): void
    {
        $eca = Domaine::where('code', 'eca')->firstOrFail();
        $eca->update(['self_registration_enabled' => true, 'email_validate_url' => 'https://eca.test/email-validate']);

        Http::fake([
            '*/api/v2/email-validate*' => Http::response(['data' => ['hs' => 1]]), // sis: a real match
            'https://eca.test/email-validate*' => Http::response(['data' => []]), // eca: no match
        ]);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Someone',
            'email' => 'sis-only@example.com',
            'password' => 'Str0ngP@ssw0rd!',
            'password_confirmation' => 'Str0ngP@ssw0rd!',
            'domaine_code' => 'eca',
        ]);

        $response->assertStatus(422);
        Http::assertSent(fn($request) => str_starts_with($request->url(), 'https://eca.test/email-validate'));
        Http::assertNotSent(fn($request) => str_contains($request->url(), '/api/v2/email-validate'));
    }
}
