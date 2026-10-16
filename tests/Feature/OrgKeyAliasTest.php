<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\Sis;
use App\Models\User;
use Tests\TestCase;

class OrgKeyAliasTest extends TestCase
{
    public function testOrgKeyHeaderResolvesTheSameOrganisationAsSisKey(): void
    {
        $sis = Sis::factory()->create(['api_key' => 'test-orgkey']);
        $user = User::factory()->create();
        $token = TokenTools::createAccessToken($user, ['test-orgkey' => ['utilisateur.lecture']], [], []);

        $response = $this->withHeaders(['Org-Key' => 'test-orgkey', 'Authorization' => "Bearer $token"])
            ->getJson('/api/v1/roles');

        $response->assertStatus(200);
    }
}
