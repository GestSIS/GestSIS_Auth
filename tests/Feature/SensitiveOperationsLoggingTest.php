<?php

namespace Tests\Feature;

use App\Auth\TokenTools;
use App\Models\Role;
use App\Models\Sapeur;
use App\Models\Sis;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SensitiveOperationsLoggingTest extends TestCase
{
    public function testReactivatingAnAccountIsLogged(): void
    {
        Mail::fake();
        Log::spy();
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $this->fakeSapeursActifs(['test' => [1]]);

        $user = User::factory()->create([
            'admin' => false,
            'pending_deactivation_at' => now()->subDays(31),
            'disabled_at' => now()->subDay(),
        ]);
        $this->linkSapeur($user, $sis, 1);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        Log::shouldHaveReceived('info')->withArgs(
            fn ($message, $context) => str_contains($message, 'réactiv') && ($context['user_id'] ?? null) === $user->id
        )->once();
    }

    public function testDisablingAnAccountIsLogged(): void
    {
        Mail::fake();
        Log::spy();
        $this->fakeSapeursActifs([]);

        $user = User::factory()->create([
            'admin' => false,
            'pending_deactivation_at' => now()->subDays(31),
        ]);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        Log::shouldHaveReceived('info')->withArgs(
            fn ($message, $context) => str_contains($message, 'désactiv') && ($context['user_id'] ?? null) === $user->id
        )->once();
    }

    public function testAdminOrganisationsAccessIsLogged(): void
    {
        Log::spy();
        $admin = User::factory()->create(['admin' => true]);
        $token = TokenTools::createAccessToken($admin, [], [], []);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/v1/admin/organisations?domaine=sis')
            ->assertStatus(200);

        Log::shouldHaveReceived('info')->withArgs(
            fn ($message, $context) => ($context['actor_id'] ?? null) === $admin->id && ($context['domaine'] ?? null) === 'sis'
        )->once();
    }

    public function testCreatingARoleIsLogged(): void
    {
        Log::spy();
        $sis = Sis::factory()->create(['api_key' => 'test-role-log']);
        $user = User::factory()->create();
        $token = TokenTools::createAccessToken($user, ['test-role-log' => ['utilisateur.config']], [], []);

        $this->withHeaders(['Sis-Key' => 'test-role-log', 'Authorization' => "Bearer $token"])
            ->postJson('/api/v1/roles', ['nom' => 'Nouveau rôle', 'description' => 'Rôle de test', 'organisation_id' => $sis->id])
            ->assertStatus(201);

        Log::shouldHaveReceived('info')->withArgs(
            fn ($message, $context) => ($context['actor_id'] ?? null) === $user->id && ($context['organisation_id'] ?? null) === $sis->id
        )->once();
    }

    public function testAssigningARoleToAUserIsLogged(): void
    {
        Log::spy();
        $sis = Sis::factory()->create(['api_key' => 'test-userrole-log']);
        $role = Role::create(['nom' => 'Rôle', 'organisation_id' => $sis->id]);
        $targetUser = User::factory()->create();
        $manager = User::factory()->create();
        $token = TokenTools::createAccessToken($manager, ['test-userrole-log' => ['utilisateur.modification']], [], []);

        $this->withHeaders(['Sis-Key' => 'test-userrole-log', 'Authorization' => "Bearer $token"])
            ->postJson("/api/v1/roles/{$role->id}/users", ['user_id' => (string) $targetUser->id])
            ->assertStatus(201);

        Log::shouldHaveReceived('info')->withArgs(
            fn ($message, $context) => ($context['actor_id'] ?? null) === $manager->id
                && ($context['role_id'] ?? null) === $role->id
                && ($context['user_id'] ?? null) === $targetUser->id
        )->once();
    }

    protected function fakeSapeursActifs(array $data): void
    {
        Http::fake([
            '*/api/v2/sapeurs-actifs*' => Http::response(['data' => $data]),
        ]);
    }

    protected function linkSapeur(User $user, Sis $sis, int $sapeurId, array $attributes = []): void
    {
        Sapeur::insert(array_merge([
            'sapeur_id' => $sapeurId,
            'sis_id' => $sis->id,
            'user_id' => $user->id,
        ], $attributes));
    }
}
