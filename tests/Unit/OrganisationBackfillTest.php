<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganisationBackfillTest extends TestCase
{
    public function testBackfillMigratesEveryExistingSisToAMatchingOrganisation(): void
    {
        DB::table('sis')->insert(['nom' => 'Ancien SIS', 'abreviation' => 'ANC', 'api_key' => 'anc']);
        // simulate pre-observer state: delete any organisation the observer may have created
        DB::table('organisations')->where('org_key', 'anc')->delete();

        // Require et exécute directement la migration (classe anonyme), plutôt que
        // Artisan::call('migrate', ...) qui échappe à la transaction de test ouverte
        // par DatabaseTransactions et laisse des données non nettoyées entre tests.
        (require database_path('migrations/2026_10_16_000003_backfill_organisations_from_sis.php'))->up();

        $sis = DB::table('sis')->where('api_key', 'anc')->first();
        $this->assertDatabaseHas('organisations', [
            'id' => $sis->id,
            'org_key' => 'anc',
            'nom' => 'Ancien SIS',
        ]);
    }

    public function testSisSeederKeepsOrganisationsInSyncForEverySeededSis(): void
    {
        // La base de test est déjà construite via `migrate:fresh --seed` (DomaineSeeder,
        // puis SisSeeder) avant que la suite ne démarre — on vérifie l'état qui en résulte,
        // sans re-seeder dans ce test (les ids 1/2 de SisSeeder sont déjà pris).
        $seededSis = DB::table('sis')->get();
        $this->assertNotEmpty($seededSis, 'La base de test doit déjà contenir les SIS seedés par SisSeeder.');

        foreach ($seededSis as $sis) {
            $this->assertDatabaseHas('organisations', [
                'id' => $sis->id,
                'org_key' => $sis->api_key,
                'nom' => $sis->nom,
            ]);
        }
    }
}
