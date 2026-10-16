<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\Domaine;
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
        $sis = Sis::find($organisationId);

        $this->assertNotNull($sis, 'Creating an organisation must create the matching Sis row');
        $this->assertSame('nouvelle-org-2', $sis->api_key);
        $this->assertSame('NO2', $sis->abreviation);
        $this->assertTrue((bool) $sis->mobile);

        // La FK sapeurs.sis_id -> sis.id ne peut être satisfaite que si la ligne
        // sis existe réellement (pas seulement organisations).
        \App\Models\Sapeur::insert(['sapeur_id' => 1, 'sis_id' => $organisationId, 'user_id' => $admin->id]);
        $this->assertDatabaseHas('sapeurs', ['sis_id' => $organisationId, 'sapeur_id' => 1]);
    }

    public function testUpdatingAnOrganisationAlsoUpdatesTheMatchingSisRow(): void
    {
        $sis = Sis::factory()->create(['mobile' => false, 'abreviation' => 'OLD']);
        $admin = User::factory()->create(['admin' => true]);
        $token = TokenTools::createAccessToken($admin, [], [], []);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/v1/admin/organisations/{$sis->id}", [
                'nom' => $sis->nom,
                'abreviation' => 'NEW',
                'mobile' => true,
            ])
            ->assertStatus(200);

        $sis->refresh();
        $this->assertSame('NEW', $sis->abreviation);
        $this->assertTrue((bool) $sis->mobile);
    }

    public function testAdminCannotCreateOrganisationWithExistingOrgKey(): void
    {
        $sis = Sis::factory()->create();
        $admin = User::factory()->create(['admin' => true]);
        $token = TokenTools::createAccessToken($admin, [], [], []);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/v1/admin/organisations', [
                'org_key' => $sis->api_key,
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
