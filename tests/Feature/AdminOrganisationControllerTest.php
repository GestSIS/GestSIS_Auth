<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\Organisation;
use App\Models\Sis;
use App\Models\User;
use Tests\TestCase;

class AdminOrganisationControllerTest extends TestCase
{
    public function testAdminCanFilterOrganisationsByDomaine(): void
    {
        $sis = Sis::factory()->create();
        $admin = User::factory()->create(['admin' => true]);
        $token = TokenTools::createAccessToken($admin, [], [], []);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/v1/admin/organisations?domaine=sis');

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $sis->id, 'org_key' => $sis->api_key]);
        $response->assertJsonCount(Organisation::whereHas('domaine', fn ($q) => $q->where('code', 'sis'))->count(), 'data');
    }

    public function testNonAdminCannotAccessAdminOrganisations(): void
    {
        $user = User::factory()->create(['admin' => false]);
        $token = TokenTools::createAccessToken($user, [], [], []);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/v1/admin/organisations')
            ->assertStatus(401);
    }
}
