<?php

namespace Tests\Unit;

use App\Models\Organisation;
use App\Models\Role;
use Tests\TestCase;

class RoleOrganisationTest extends TestCase
{
    public function testRoleBelongsToAnOrganisationMatchingItsFormerSis(): void
    {
        $sis = Organisation::factory()->create();
        $role = Role::create(['nom' => 'Test', 'organisation_id' => $sis->id]);

        $this->assertInstanceOf(Organisation::class, $role->organisation);
        $this->assertSame($sis->id, $role->organisation->id);
        $this->assertSame($sis->org_key, $role->organisation->org_key);
    }
}
