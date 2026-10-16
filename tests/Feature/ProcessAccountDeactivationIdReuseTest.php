<?php

namespace Tests\Feature;

use App\Models\Sapeur;
use App\Models\Sis;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessAccountDeactivationIdReuseTest extends TestCase
{
    public function testReactivationIsNotTriggeredWhenAReusedSapeurIdBelongsToADifferentPerson(): void
    {
        // Sapeur A, désactivé, id 5 dans le SIS X
        $sis = Sis::factory()->create();
        $user = User::factory()->create(['disabled_at' => now()]);
        Sapeur::insert([
            'sapeur_id' => 5,
            'sis_id' => $sis->id,
            'user_id' => $user->id,
            'deactivated_at' => now()->subDay(),
        ]);

        // Le SIS réattribue sapeur_id=5 à une personne B (nouvelle ligne active, compte distinct)
        $otherUser = User::factory()->create();
        Sapeur::insert([
            'sapeur_id' => 5,
            'sis_id' => $sis->id,
            'user_id' => $otherUser->id,
            'deactivated_at' => null,
        ]);

        Http::fake([
            '*/api/v2/sapeurs-actifs*' => Http::response(['data' => [$sis->api_key => [5]]]),
        ]);

        Artisan::call('users:process-deactivation');

        $user->refresh();
        $this->assertNotNull($user->disabled_at, "Le compte de la personne A ne doit pas être réactivé par la présence du sapeur_id=5 réattribué.");
    }
}
