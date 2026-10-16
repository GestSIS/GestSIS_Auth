<?php

namespace Tests\Unit;

use App\Models\Domaine;
use App\Models\Organisation;
use Tests\TestCase;

class OrganisationFactoryTest extends TestCase
{
    public function testFactoryCreatesASisDomaineOrganisationByDefault(): void
    {
        $organisation = Organisation::factory()->create();

        $this->assertSame(Domaine::where('code', 'sis')->value('id'), $organisation->domaine_id);
        $this->assertFalse($organisation->mobile);
        $this->assertNotNull($organisation->org_key);
    }
}
