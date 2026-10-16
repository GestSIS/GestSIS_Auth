<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\Domaine;
use App\Models\Organisation;
use App\Models\User;
use Tests\TestCase;

class AdminOrganisationControllerTest extends TestCase
{
    public function testAdminCanFilterOrganisationsByDomaine(): void
    {
        $sis = Organisation::factory()->create();
        $admin = User::factory()->create(['admin' => true]);
        $token = TokenTools::createAccessToken($admin, [], [], []);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/v1/admin/organisations?domaine=sis');

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $sis->id, 'org_key' => $sis->org_key]);
        $response->assertJsonCount(Organisation::whereHas('domaine', fn ($q) => $q->where('code', 'sis'))->count(), 'data');
    }

    public function testAdminCanCreateOrganisationInSisDomaine(): void
    {
        $admin = User::factory()->create(['admin' => true]);
        $token = TokenTools::createAccessToken($admin, [], [], []);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/v1/admin/organisations', [
                'org_key' => 'nouvelle-org',
                'nom' => 'Nouvelle organisation',
                'abreviation' => 'NO',
                'mobile' => true,
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['org_key' => 'nouvelle-org', 'nom' => 'Nouvelle organisation']);
        $this->assertDatabaseHas('organisations', [
            'org_key' => 'nouvelle-org',
            'domaine_id' => Domaine::where('code', 'sis')->value('id'),
            'mobile' => true,
        ]);
    }

    /**
     * `AdminOrganisationController` manage le seul domaine `sis` aujourd'hui, où
     * `organisations.id === sis.id` est une invariante exploitée directement par
     * `User::getMobile()`/`getSapeurs()` (jointures sur `sis`, pas `organisations`)
     * et par la FK `sapeurs.sis_id -> sis.id`. Créer/modifier une organisation sans
     * passer par `Sis` (et donc par `SisObserver`) casse cette invariante : aucun
     * lien sapeur n'est alors insérable, et `mobile`/`abreviation` ne reflètent
     * jamais `sis`.
     */
    public function testCreatingAnOrganisationAlsoCreatesTheMatchingSisRow(): void
    {
        $admin = User::factory()->create(['admin' => true]);
        $token = TokenTools::createAccessToken($admin, [], [], []);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/v1/admin/organisations', [
                'org_key' => 'nouvelle-org-2',
                'nom' => 'Nouvelle organisation 2',
                'abreviation' => 'NO2',
                'mobile' => true,
            ]);

        $organisationId = $response->json('data.id');
        $organisation = Organisation::find($organisationId);

        $this->assertNotNull($organisation, 'Creating an organisation must create the Organisation row');
        $this->assertSame('nouvelle-org-2', $organisation->org_key);
        $this->assertSame('NO2', $organisation->abreviation);
        $this->assertTrue((bool) $organisation->mobile);

        // La FK sapeurs.organisation_id -> organisations.id doit être satisfaite
        // directement (plus besoin d'une ligne sis derrière, depuis Task 2).
        \App\Models\Sapeur::insert(['sapeur_id' => 1, 'organisation_id' => $organisationId, 'user_id' => $admin->id]);
        $this->assertDatabaseHas('sapeurs', ['organisation_id' => $organisationId, 'sapeur_id' => 1]);
    }

    public function testUpdatingAnOrganisationAlsoUpdatesTheMatchingSisRow(): void
    {
        $organisation = Organisation::factory()->create(['mobile' => false, 'abreviation' => 'OLD']);
        $admin = User::factory()->create(['admin' => true]);
        $token = TokenTools::createAccessToken($admin, [], [], []);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/v1/admin/organisations/{$organisation->id}", [
                'nom' => $organisation->nom,
                'abreviation' => 'NEW',
                'mobile' => true,
            ])
            ->assertStatus(200);

        $organisation->refresh();
        $this->assertSame('NEW', $organisation->abreviation);
        $this->assertTrue((bool) $organisation->mobile);
    }

    public function testAdminCannotCreateOrganisationWithExistingOrgKey(): void
    {
        $sis = Organisation::factory()->create();
        $admin = User::factory()->create(['admin' => true]);
        $token = TokenTools::createAccessToken($admin, [], [], []);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/v1/admin/organisations', [
                'org_key' => $sis->org_key,
                'nom' => 'Doublon',
                'mobile' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['org_key']);
    }

    public function testAdminCannotCreateOrganisationWithoutOrgKey(): void
    {
        $admin = User::factory()->create(['admin' => true]);
        $token = TokenTools::createAccessToken($admin, [], [], []);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/v1/admin/organisations', [
                'nom' => 'Sans clé',
                'mobile' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['org_key']);
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
