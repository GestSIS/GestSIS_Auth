<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\Organisation;
use App\Models\User;
use Tests\TestCase;

class OrganisationControllerTest extends TestCase
{
    public function testIndexReturnsOrganisationsForTheCallerDomaineOnly(): void
    {
        $sis = Organisation::factory()->create();
        $user = User::factory()->create();
        $token = TokenTools::createAccessToken($user, [], [], []); // aud = GestSIS_API par défaut

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/v1/organisations');

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $sis->id, 'org_key' => $sis->org_key]);
    }
}
