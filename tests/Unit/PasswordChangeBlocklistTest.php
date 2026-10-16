<?php

namespace Tests\Unit;

use App\Models\Domaine;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordChangeBlocklistTest extends TestCase
{
    public function testPasswordChangeBlockedForConfiguredTestDomainSuffix(): void
    {
        Domaine::where('code', 'sis')->update(['test_account_domain_suffix' => '@example-test.ch']);
        $user = User::factory()->create([
            'email' => 'admin@example-test.ch',
            'password' => Hash::make('a-very-long-password'),
        ]);

        $response = $this->postJson('/api/v1/change-password', [
            'email' => $user->email,
            'password' => 'a-very-long-password',
            'new_password' => 'un-nouveau-mot-de-passe',
        ]);

        $response->assertStatus(401);
    }
}
