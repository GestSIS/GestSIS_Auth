<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\Sapeur;
use App\Models\Sis;
use App\Models\User;
use Tests\TestCase;

class AdminSapeurControllerTest extends TestCase
{
    public function testAdminCanDeleteASapeurMapping(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $user = User::factory()->create();
        Sapeur::insert(['sapeur_id' => 42, 'sis_id' => $sis->id, 'user_id' => $user->id]);
        $link = Sapeur::where('user_id', $user->id)->first();

        $admin = User::factory()->create(['admin' => true]);
        $token = TokenTools::createAccessToken($admin, [], [], [], true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->deleteJson("/api/v1/admin/sapeurs/{$link->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('sapeurs', ['id' => $link->id]);
    }

    public function testNonAdminCannotDeleteASapeurMapping(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $user = User::factory()->create();
        Sapeur::insert(['sapeur_id' => 42, 'sis_id' => $sis->id, 'user_id' => $user->id]);
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
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $user = User::factory()->create();
        Sapeur::insert(['sapeur_id' => 42, 'sis_id' => $sis->id, 'user_id' => $user->id]);
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
