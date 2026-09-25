<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\Role;
use App\Models\Sis;
use App\Models\User;
use App\Models\UserRole;
use Tests\TestCase;

/**
 * Régression : `User::$hidden` doit masquer `two_factor_secret`. Le cast
 * `encrypted` déchiffre la valeur lors de la sérialisation JSON — sans
 * `$hidden`, la liste/fiche admin exposait le secret TOTP en clair de
 * n'importe quel compte, permettant à un admin de cloner le 2FA de
 * n'importe quel utilisateur silencieusement.
 */
class AdminUserControllerTwoFactorSecretExposureTest extends TestCase
{
    protected function adminToken(): string
    {
        $admin = User::factory()->create(['admin' => true]);

        return TokenTools::createAccessToken($admin, [], [], [], true);
    }

    public function testIndexDoesNotExposeTwoFactorSecrets(): void
    {
        User::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken()])
            ->getJson('/api/v1/admin/users');

        $response->assertOk();
        $response->assertJsonMissingPath('data.0.two_factor_secret');
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $response->getContent());
    }

    public function testShowDoesNotExposeTheTwoFactorSecret(): void
    {
        $user = User::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken()])
            ->getJson("/api/v1/admin/users/{$user->id}");

        $response->assertOk();
        $response->assertJsonMissingPath('data.two_factor_secret');
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $response->getContent());
    }

    /**
     * `GET /users` est ouvert aux responsables SIS (non admin) : l'état 2FA des
     * autres comptes (sans 2FA, exemptés et pourquoi) ne doit pas y figurer —
     * ce serait une liste des comptes les plus faciles à cibler.
     */
    public function testSisManagerUserListDoesNotExposeTwoFactorState(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $role = Role::create(['nom' => 'Rôle test', 'sis_id' => $sis->id]);
        $member = User::factory()->create([
            'two_factor_exempt' => true,
            'two_factor_exempt_reason' => 'Tablette partagée caserne',
            'two_factor_exempt_until' => now()->addMonth(),
        ]);
        UserRole::create(['user_id' => $member->id, 'role_id' => $role->id]);
        $manager = User::factory()->create();

        $response = $this->withHeaders([
            'Sis-Key' => 'test',
            'Authorization' => 'Bearer ' . TokenTools::createAccessToken($manager, ['test' => ['utilisateur.tout']], [], []),
        ])->getJson('/api/v1/users');

        $response->assertOk();
        $listed = collect($response->json('data'))->firstWhere('id', $member->id);
        $this->assertNotNull($listed);
        foreach (['two_factor_confirmed_at', 'two_factor_last_used_timestep', ...User::TWO_FACTOR_ADMIN_ATTRIBUTES] as $attribute) {
            $this->assertArrayNotHasKey($attribute, $listed);
        }
        $this->assertStringNotContainsString('Tablette partagée caserne', $response->getContent());
    }

    public function testAdminUserDetailStillExposesTheExemption(): void
    {
        $user = User::factory()->create([
            'two_factor_exempt' => true,
            'two_factor_exempt_reason' => 'Tablette partagée caserne',
        ]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken()])
            ->getJson("/api/v1/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.two_factor_exempt', true)
            ->assertJsonPath('data.two_factor_exempt_reason', 'Tablette partagée caserne');
    }
}
