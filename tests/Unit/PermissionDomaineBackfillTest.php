<?php

namespace Tests\Unit;

use App\Models\Domaine;
use Tests\TestCase;

class PermissionDomaineBackfillTest extends TestCase
{
    public function testBackfillSetsSisDomaineOnBusinessPermissionsAndNullOnUtilisateur(): void
    {
        $sisDomaineId = Domaine::where('code', 'sis')->firstOrFail()->id;

        $this->assertDatabaseHas('permissions', ['api_key' => 'sapeur.lecture', 'domaine_id' => $sisDomaineId]);
        $this->assertDatabaseHas('permissions', ['api_key' => 'utilisateur.config', 'domaine_id' => null]);
        $this->assertDatabaseHas('permissions', ['api_key' => 'utilisateur.lecture', 'domaine_id' => null]);
        $this->assertDatabaseHas('permissions', ['api_key' => 'utilisateur.modification', 'domaine_id' => null]);
    }
}
