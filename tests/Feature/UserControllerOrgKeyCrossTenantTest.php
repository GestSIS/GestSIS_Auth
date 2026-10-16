<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\Sapeur;
use App\Models\Sis;
use App\Models\User;
use Tests\TestCase;

class UserControllerOrgKeyCrossTenantTest extends TestCase
{
    /**
     * `JwtTokenValidatorRole` authorises against `Org-Key ?? Sis-Key`
     * (Task 9), but `UserController::index` used to read `Sis-Key` alone:
     * a caller sending both headers with different values was authorised
     * against one SIS and served the other's users.
     */
    public function testIndexScopesUsersToTheOrgKeyAuthorisedSisWhenBothHeadersDiffer(): void
    {
        $sisA = Sis::factory()->create(['api_key' => 'org-a']);
        $sisB = Sis::factory()->create(['api_key' => 'org-b']);

        $userA = User::factory()->create(['email' => 'a@example.com']);
        Sapeur::insert(['sapeur_id' => 1, 'sis_id' => $sisA->id, 'user_id' => $userA->id]);

        $userB = User::factory()->create(['email' => 'b@example.com']);
        Sapeur::insert(['sapeur_id' => 1, 'sis_id' => $sisB->id, 'user_id' => $userB->id]);

        $manager = User::factory()->create();
        $token = TokenTools::createAccessToken($manager, ['org-a' => ['utilisateur.lecture']], [], []);

        $response = $this->withHeaders([
            'Org-Key' => 'org-a',
            'Sis-Key' => 'org-b',
            'Authorization' => "Bearer $token",
        ])->getJson('/api/v1/users');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($userA->id), 'Expected the Org-Key-authorised SIS\'s user to be present');
        $this->assertFalse($ids->contains($userB->id), 'The caller has no permission on org-b; its users must not leak');
    }
}
