<?php

namespace Tests\Unit;

use App\Models\Organisation;
use App\Models\Sapeur;
use App\Models\User;
use Tests\TestCase;

class SapeurOrganisationRelationTest extends TestCase
{
    public function testSapeurOrganisationRelationResolvesToOrganisation(): void
    {
        $organisation = Organisation::factory()->create();
        $user = User::factory()->create();
        Sapeur::insert(['sapeur_id' => 1, 'organisation_id' => $organisation->id, 'user_id' => $user->id]);

        $link = Sapeur::with('organisation')->where('organisation_id', $organisation->id)->firstOrFail();

        $this->assertInstanceOf(Organisation::class, $link->organisation);
        $this->assertSame($organisation->id, $link->organisation->id);
    }
}
