<?php

namespace Tests\Unit;

use App\Models\Organisation;
use App\Models\Role;
use App\Models\Sapeur;
use App\Models\User;
use App\Models\UserRole;
use Tests\TestCase;

class UserGetMobileOrganisationTest extends TestCase
{
    public function testGetMobileReturnsOrgKeysFromOrganisationsNotSis(): void
    {
        $organisation = Organisation::factory()->create(['mobile' => true]);
        $role = Role::create(['nom' => 'R', 'organisation_id' => $organisation->id]);
        $user = User::factory()->create();
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $this->assertSame([$organisation->org_key], User::getMobile($user->id));
    }

    public function testGetMobileExcludesOrganisationsWithMobileFalse(): void
    {
        $organisation = Organisation::factory()->create(['mobile' => false]);
        $role = Role::create(['nom' => 'R', 'organisation_id' => $organisation->id]);
        $user = User::factory()->create();
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $this->assertSame([], User::getMobile($user->id));
    }

    public function testGetSapeursReturnsOrgKeysFromOrganisationsNotSis(): void
    {
        $organisation = Organisation::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);
        Sapeur::insert(['sapeur_id' => 7, 'organisation_id' => $organisation->id, 'user_id' => $user->id]);

        $this->assertSame([$organisation->org_key => 7], User::getSapeurs($user->id));
    }
}
