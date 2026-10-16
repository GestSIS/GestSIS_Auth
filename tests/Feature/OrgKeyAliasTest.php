<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\Organisation;
use App\Models\User;
use Tests\TestCase;

class OrgKeyAliasTest extends TestCase
{
    public function testOrgKeyHeaderResolvesTheSameOrganisationAsSisKey(): void
    {
        $sis = Organisation::factory()->create(['org_key' => 'test-orgkey']);
        $user = User::factory()->create();
        $token = TokenTools::createAccessToken($user, ['test-orgkey' => ['utilisateur.lecture']], [], []);

        $response = $this->withHeaders(['Org-Key' => 'test-orgkey', 'Authorization' => "Bearer $token"])
            ->getJson('/api/v1/roles');

        $response->assertStatus(200);
    }
}
