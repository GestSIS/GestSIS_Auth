<?php

namespace Tests\Unit;

use App\Models\Sapeur;
use App\Models\User;
use Tests\TestCase;

class SapeursOrganisationIdForeignKeyTest extends TestCase
{
    public function testSapeurLinkCanReferenceAPreexistingSeededOrganisationId(): void
    {
        // ids 1/2 are OrganisationSeeder-seeded (Task 11), not freshly created --
        // proves the repoint accepts pre-existing data, not just new rows.
        Sapeur::insert(['sapeur_id' => 999, 'organisation_id' => 1, 'user_id' => User::factory()->create()->id]);

        $this->assertDatabaseHas('sapeurs', ['organisation_id' => 1, 'sapeur_id' => 999]);
    }

    public function testSapeurLinkRejectsAnIdWithNoMatchingOrganisation(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        Sapeur::insert(['sapeur_id' => 998, 'organisation_id' => 999999, 'user_id' => User::factory()->create()->id]);
    }
}
