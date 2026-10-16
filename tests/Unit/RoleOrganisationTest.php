<?php

namespace Tests\Unit;

use App\Models\Organisation;
use App\Models\Role;
use App\Models\Sis;
use Tests\TestCase;

class RoleOrganisationTest extends TestCase
{
    public function testRoleBelongsToAnOrganisationMatchingItsFormerSis(): void
    {
        $sis = Sis::factory()->create();
        $role = Role::create(['nom' => 'Test', 'organisation_id' => $sis->id]);

        $this->assertInstanceOf(Organisation::class, $role->organisation);
        $this->assertSame($sis->id, $role->organisation->id);
        $this->assertSame($sis->api_key, $role->organisation->org_key);
    }
}
