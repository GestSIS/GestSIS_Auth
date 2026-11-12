<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Domaine;
use App\Models\Organisation;
use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class DBCustomSeedMaterielControleTest extends TestCase
{
    private const MATERIEL_MODIFICATION_ID = 25;
    private const MATERIEL_CONTROLE_ID = 47;

    protected function setUp(): void
    {
        parent::setUp();

        // Reproduit une base existante, antérieure à la permission materiel.controle.
        Permission::where('id', self::MATERIEL_CONTROLE_ID)->delete();
    }

    public function testCreatesMaterielControlePermissionWithFixedIdOnSisDomaine(): void
    {
        $this->artisan('db:seed-custom')->assertExitCode(0);

        $this->assertDatabaseHas('permissions', [
            'id' => self::MATERIEL_CONTROLE_ID,
            'api_key' => 'materiel.controle',
            'domaine_id' => Domaine::where('code', 'sis')->value('id'),
        ]);
    }

    public function testAssignsMaterielControleToRolesWithMaterielModificationOnly(): void
    {
        $organisationId = Organisation::factory()->create()->id;
        $roleAvecModification = Role::create(['nom' => 'Responsable matériel', 'organisation_id' => $organisationId]);
        $roleSansModification = Role::create(['nom' => 'Lecteur matériel', 'organisation_id' => $organisationId]);
        PermissionRole::create(['permission_id' => self::MATERIEL_MODIFICATION_ID, 'role_id' => $roleAvecModification->id]);
        PermissionRole::create(['permission_id' => 24, 'role_id' => $roleSansModification->id]);

        $this->artisan('db:seed-custom')->assertExitCode(0);
        // Idempotente : une seconde exécution ne crée pas de doublon.
        $this->artisan('db:seed-custom')->assertExitCode(0);

        $this->assertSame(1, PermissionRole::where([
            'permission_id' => self::MATERIEL_CONTROLE_ID,
            'role_id' => $roleAvecModification->id,
        ])->count());
        $this->assertDatabaseMissing('permission_roles', [
            'permission_id' => self::MATERIEL_CONTROLE_ID,
            'role_id' => $roleSansModification->id,
        ]);
    }

    public function testAssignsMaterielControleToApiTokensWithMaterielModification(): void
    {
        $user = User::factory()->create();
        $apiToken = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'Intégration matériel',
            'token' => 'hash-materiel',
            'expires_at' => now()->addDays(30),
        ]);
        $apiToken->permissions()->attach([self::MATERIEL_MODIFICATION_ID]);

        $this->artisan('db:seed-custom')->assertExitCode(0);
        $this->artisan('db:seed-custom')->assertExitCode(0);

        $this->assertSame(1, $apiToken->permissions()->where('permissions.id', self::MATERIEL_CONTROLE_ID)->count());
    }
}
