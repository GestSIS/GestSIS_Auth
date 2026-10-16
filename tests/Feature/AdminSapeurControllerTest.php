<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\Sapeur;
use App\Models\Organisation;
use App\Models\Domaine;
use App\Models\User;
use Tests\TestCase;

class AdminSapeurControllerTest extends TestCase
{
    public function testAdminCanDeleteASapeurMapping(): void
    {
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $user = User::factory()->create();
        Sapeur::insert(['sapeur_id' => 42, 'organisation_id' => $sis->id, 'user_id' => $user->id]);
        $link = Sapeur::where('user_id', $user->id)->first();

        $admin = User::factory()->create(['admin' => true]);
        $token = TokenTools::createAccessToken($admin, [], [], [], true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->deleteJson("/api/v1/admin/sapeurs/{$link->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('sapeurs', ['id' => $link->id]);
    }

    public function testNonAdminCannotDeleteASapeurMapping(): void
    {
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $user = User::factory()->create();
        Sapeur::insert(['sapeur_id' => 42, 'organisation_id' => $sis->id, 'user_id' => $user->id]);
        $link = Sapeur::where('user_id', $user->id)->first();

        $regularUser = User::factory()->create();
        $token = TokenTools::createAccessToken($regularUser, [], [], []);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->deleteJson("/api/v1/admin/sapeurs/{$link->id}");

        $response->assertStatus(401);
        $this->assertDatabaseHas('sapeurs', ['id' => $link->id]);
    }

    public function testDisabledAdminCannotUseAdminEndpointsEvenWithAValidToken(): void
    {
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $user = User::factory()->create();
        Sapeur::insert(['sapeur_id' => 42, 'organisation_id' => $sis->id, 'user_id' => $user->id]);
        $link = Sapeur::where('user_id', $user->id)->first();

        $admin = User::factory()->create(['disabled_at' => now()]);
        $token = TokenTools::createAccessToken($admin, [], [], [], true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->deleteJson("/api/v1/admin/sapeurs/{$link->id}");

        $response->assertStatus(401);
        $this->assertDatabaseHas('sapeurs', ['id' => $link->id]);
    }
}
