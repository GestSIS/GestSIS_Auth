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

class DBCustomSeedInventaireTest extends TestCase
{
    private const MATERIEL_LECTURE_ID = 24;
    private const MATERIEL_MODIFICATION_ID = 25;
    private const MATERIEL_INVENTAIRE_ID = 48;

    protected function setUp(): void
    {
        parent::setUp();

        // Reproduit une base existante, antérieure à la permission materiel.inventaire.
        Permission::where('id', self::MATERIEL_INVENTAIRE_ID)->delete();
    }

    public function testCreatesMaterielInventairePermissionWithFixedIdOnSisDomaine(): void
    {
        $this->artisan('db:seed-custom')->assertExitCode(0);

        $this->assertDatabaseHas('permissions', [
            'id' => self::MATERIEL_INVENTAIRE_ID,
            'api_key' => 'materiel.inventaire',
            'domaine_id' => Domaine::where('code', 'sis')->value('id'),
        ]);
    }

    public function testAssignsMaterielInventaireToRolesWithMaterielModificationOnly(): void
    {
        $organisationId = Organisation::factory()->create()->id;
        $roleAvecModification = Role::create(['nom' => 'Responsable matériel', 'organisation_id' => $organisationId]);
        $roleSansModification = Role::create(['nom' => 'Lecteur matériel', 'organisation_id' => $organisationId]);
        PermissionRole::create(['permission_id' => self::MATERIEL_MODIFICATION_ID, 'role_id' => $roleAvecModification->id]);
        PermissionRole::create(['permission_id' => self::MATERIEL_LECTURE_ID, 'role_id' => $roleSansModification->id]);

        $this->artisan('db:seed-custom')->assertExitCode(0);
        // Idempotente : une seconde exécution ne crée pas de doublon.
        $this->artisan('db:seed-custom')->assertExitCode(0);

        $this->assertSame(1, PermissionRole::where([
            'permission_id' => self::MATERIEL_INVENTAIRE_ID,
            'role_id' => $roleAvecModification->id,
        ])->count());
        $this->assertDatabaseMissing('permission_roles', [
            'permission_id' => self::MATERIEL_INVENTAIRE_ID,
            'role_id' => $roleSansModification->id,
        ]);
    }

    public function testAssignsMaterielInventaireToApiTokensWithMaterielModification(): void
    {
        $user = User::factory()->create();
        $apiToken = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'Intégration matériel',
            'token' => 'hash-inventaire',
            'expires_at' => now()->addDays(30),
        ]);
        $apiToken->permissions()->attach([self::MATERIEL_MODIFICATION_ID]);

        $this->artisan('db:seed-custom')->assertExitCode(0);
        $this->artisan('db:seed-custom')->assertExitCode(0);

        $this->assertSame(1, $apiToken->permissions()->where('permissions.id', self::MATERIEL_INVENTAIRE_ID)->count());
    }
}
