<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\Organisation;
use App\Models\RegisterToken;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ApiConfirmerEmailControllerSapeurLinkTest extends TestCase
{
    private function registerViaInviteToken(string $email = 'nouveau@example.com'): void
    {
        Mail::fake();
        RegisterToken::create([
            'token' => TokenTools::hashToken('un-jeton-invitation'),
            'description' => 'Test',
            'validite' => now()->addDay(),
        ]);

        $this->postJson('/api/v1/register', [
            'name' => 'Nouveau Sapeur',
            'email' => $email,
            'password' => 'a-very-long-password',
            'password_confirmation' => 'a-very-long-password',
            'token' => 'un-jeton-invitation',
        ]);
    }

    private function extractSentCode(): string
    {
        $captured = null;
        Mail::assertSent(\App\Mail\ConfirmationEmail::class, function ($mail) use (&$captured) {
            $captured = $mail->code;
            return true;
        });

        $this->assertNotNull($captured);

        return $captured;
    }

    public function testConfirmingEmailLinksSapeurByOrganisationOrgKey(): void
    {
        $organisation = Organisation::factory()->create(['org_key' => 'confirm-link']);
        // confirmerEmail() iterates $response['data'] as $sisKey => $sapeurId -- an
        // associative map of every SIS this email is a known sapeur in, not a scalar.
        Http::fake(['*/api/v2/email-validate*' => Http::response(['data' => ['confirm-link' => 42]])]);
        $this->registerViaInviteToken('confirme-lien@example.com');
        $user = User::where('email', 'confirme-lien@example.com')->first();
        $plainCode = $this->extractSentCode();

        $this->postJson('/api/v1/confirmer-email', ['email' => 'confirme-lien@example.com', 'code' => $plainCode])
            ->assertOk();

        $this->assertDatabaseHas('sapeurs', ['organisation_id' => $organisation->id, 'sapeur_id' => 42, 'user_id' => $user->id]);
    }
}
