<?php

namespace Tests\Feature;

use App\Mail\AccountPendingDeactivationMail;
use App\Mail\SapeurAccessPendingDeactivationMail;
use App\Models\ApiToken;
use App\Models\LegacyRefreshToken;
use App\Models\Role;
use App\Models\Sapeur;
use App\Models\Organisation;
use App\Models\Domaine;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProcessAccountDeactivationTest extends TestCase
{
    protected function fakeSapeursActifs(array $data): void
    {
        Http::fake([
            '*/api/v2/identites-actives*' => Http::response(['data' => $data]),
        ]);
    }

    protected function linkSapeur(User $user, Organisation $sis, int $sapeurId, array $attributes = []): Sapeur
    {
        Sapeur::insert(array_merge([
            'sapeur_id' => $sapeurId,
            'organisation_id' => $sis->id,
            'user_id' => $user->id,
        ], $attributes));

        return Sapeur::where('sapeur_id', $sapeurId)->where('organisation_id', $sis->id)->firstOrFail();
    }

    public function testFlagsAccountWithoutRoleAndWithoutActiveSapeur(): void
    {
        Mail::fake();
        $this->fakeSapeursActifs(['test' => []]);

        $user = User::factory()->create(['admin' => false]);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $user->refresh();
        $this->assertNotNull($user->pending_deactivation_at);
        $this->assertNull($user->disabled_at);
        Mail::assertSent(AccountPendingDeactivationMail::class, fn($mail) => $mail->user->id === $user->id);
    }

    public function testDoesNotFlagAccountStillLinkedToAnActiveSapeur(): void
    {
        Mail::fake();
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $this->fakeSapeursActifs(['test' => [123 => '']]);

        $user = User::factory()->create(['admin' => false]);
        $this->linkSapeur($user, $sis, 123);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $user->refresh();
        $this->assertNull($user->pending_deactivation_at);
        Mail::assertNotSent(AccountPendingDeactivationMail::class, fn($mail) => $mail->user->id === $user->id);
    }

    public function testDoesNotFlagAdminAccount(): void
    {
        Mail::fake();
        $this->fakeSapeursActifs(['test' => []]);

        $admin = User::factory()->create(['admin' => true]);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $admin->refresh();
        $this->assertNull($admin->pending_deactivation_at);
        Mail::assertNotSent(AccountPendingDeactivationMail::class, fn($mail) => $mail->user->id === $admin->id);
    }

    public function testDisablesAccountPastGracePeriodAndRevokesTokens(): void
    {
        Mail::fake();
        $this->fakeSapeursActifs(['test' => []]);

        $user = User::factory()->create([
            'admin' => false,
            'pending_deactivation_at' => now()->subDay(),
        ]);

        [, $session] = $this->issueSession($user);
        $legacyToken = LegacyRefreshToken::create(['token' => 'hashed-token', 'expire' => now()->addDays(30), 'user_id' => $user->id]);

        $apiToken = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'Token',
            'token' => 'hashed-api-token',
            'expires_at' => now()->addDays(30),
        ]);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $user->refresh();
        $this->assertNotNull($user->disabled_at);
        $this->assertDatabaseMissing('auth_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('refresh_tokens', ['id' => $legacyToken->id]);
        $this->assertDatabaseMissing('api_tokens', ['id' => $apiToken->id]);
    }

    public function testReinstatesAccountThatRegainedARole(): void
    {
        Mail::fake();
        $this->fakeSapeursActifs(['test' => []]);
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );

        $user = User::factory()->create([
            'admin' => false,
            'pending_deactivation_at' => now()->addDays(10),
        ]);
        $role = Role::create(['nom' => 'Role réhabilité', 'organisation_id' => $sis->id]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $user->refresh();
        $this->assertNull($user->pending_deactivation_at);
        $this->assertNull($user->disabled_at);
        Mail::assertNotSent(AccountPendingDeactivationMail::class, fn($mail) => $mail->user->id === $user->id);
    }

    public function testReactivatesAccountThatRegainedARoleAfterBeingDisabled(): void
    {
        Mail::fake();
        $this->fakeSapeursActifs(['test' => []]);
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );

        $user = User::factory()->create([
            'admin' => false,
            'pending_deactivation_at' => now()->subDays(31),
            'disabled_at' => now()->subDay(),
        ]);
        $role = Role::create(['nom' => 'Role réattribué', 'organisation_id' => $sis->id]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $user->refresh();
        $this->assertNull($user->disabled_at);
        $this->assertNull($user->pending_deactivation_at);
        Mail::assertNotSent(AccountPendingDeactivationMail::class, fn($mail) => $mail->user->id === $user->id);
    }

    public function testReactivatesAccountThatBecameActiveSapeurAgainAfterBeingDisabled(): void
    {
        Mail::fake();
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $this->fakeSapeursActifs(['test' => [1 => '']]);

        $user = User::factory()->create([
            'admin' => false,
            'pending_deactivation_at' => now()->subDays(31),
            'disabled_at' => now()->subDay(),
        ]);
        $this->linkSapeur($user, $sis, 1);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $user->refresh();
        $this->assertNull($user->disabled_at);
        Mail::assertNotSent(AccountPendingDeactivationMail::class, fn($mail) => $mail->user->id === $user->id);
    }

    public function testDoesNotReactivateAccountStillWithoutRoleOrActiveSapeur(): void
    {
        Mail::fake();
        $this->fakeSapeursActifs(['test' => []]);

        $user = User::factory()->create([
            'admin' => false,
            'pending_deactivation_at' => now()->subDays(31),
            'disabled_at' => now()->subDay(),
        ]);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $user->refresh();
        $this->assertNotNull($user->disabled_at);
    }

    public function testDryRunDoesNotReactivateAccount(): void
    {
        Mail::fake();
        $this->fakeSapeursActifs(['test' => []]);
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );

        $user = User::factory()->create([
            'admin' => false,
            'pending_deactivation_at' => now()->subDays(31),
            'disabled_at' => now()->subDay(),
        ]);
        $role = Role::create(['nom' => 'Role', 'organisation_id' => $sis->id]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $this->artisan('users:process-deactivation', ['--dry-run' => true])->assertExitCode(0);

        $user->refresh();
        $this->assertNotNull($user->disabled_at);
    }

    public function testDryRunDoesNotWriteAnythingOrSendEmail(): void
    {
        Mail::fake();
        $this->fakeSapeursActifs(['test' => []]);

        $user = User::factory()->create(['admin' => false]);

        $this->artisan('users:process-deactivation', ['--dry-run' => true])->assertExitCode(0);

        $user->refresh();
        $this->assertNull($user->pending_deactivation_at);
        Mail::assertNothingSent();
    }

    public function testFlagsStaleSapeurLinkWithEmailWhenUserStillActiveInAnotherSis(): void
    {
        Mail::fake();
        $sisA = Organisation::firstOrCreate(
            ['org_key' => 'sis_a'],
            ['nom' => 'SIS A', 'abreviation' => 'SA', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $sisB = Organisation::firstOrCreate(
            ['org_key' => 'sis_b'],
            ['nom' => 'SIS B', 'abreviation' => 'SB', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $this->fakeSapeursActifs(['sis_a' => [1 => ''], 'sis_b' => []]);

        $user = User::factory()->create(['admin' => false]);
        $this->linkSapeur($user, $sisA, 1);
        $staleLink = $this->linkSapeur($user, $sisB, 2);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $staleLink->refresh();
        $this->assertNotNull($staleLink->pending_deactivation_at);
        $this->assertNull($staleLink->deactivated_at);
        Mail::assertSent(
            SapeurAccessPendingDeactivationMail::class,
            fn($mail) => $mail->user->id === $user->id && $mail->sapeurLink->id === $staleLink->id
        );
        Mail::assertNotSent(AccountPendingDeactivationMail::class, fn($mail) => $mail->user->id === $user->id);

        $activeLink = Sapeur::where('organisation_id', $sisA->id)->first();
        $this->assertNull($activeLink->pending_deactivation_at);
    }

    public function testFlagsStaleSapeurLinkWithoutEmailWhenItIsAFullDeparture(): void
    {
        Mail::fake();
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $this->fakeSapeursActifs(['test' => []]);

        $user = User::factory()->create(['admin' => false]);
        $staleLink = $this->linkSapeur($user, $sis, 1);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $staleLink->refresh();
        $this->assertNotNull($staleLink->pending_deactivation_at);
        Mail::assertNotSent(SapeurAccessPendingDeactivationMail::class);
        // Le départ complet reste notifié, mais au niveau du compte, pas du lien.
        Mail::assertSent(AccountPendingDeactivationMail::class);
    }

    public function testCutsSapeurLinkAccessPastGracePeriod(): void
    {
        Mail::fake();
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $this->fakeSapeursActifs(['test' => []]);

        $user = User::factory()->create(['admin' => false]);
        $link = $this->linkSapeur($user, $sis, 1, ['pending_deactivation_at' => now()->subDay()]);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $link->refresh();
        $this->assertNotNull($link->deactivated_at);
    }

    public function testReinstatesSapeurLinkThatBecameActiveAgain(): void
    {
        Mail::fake();
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $this->fakeSapeursActifs(['test' => [1 => '']]);

        $user = User::factory()->create(['admin' => false]);
        $link = $this->linkSapeur($user, $sis, 1, ['pending_deactivation_at' => now()->addDays(10)]);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $link->refresh();
        $this->assertNull($link->pending_deactivation_at);
        $this->assertNull($link->deactivated_at);
        Mail::assertNotSent(SapeurAccessPendingDeactivationMail::class, fn($mail) => $mail->sapeurLink->id === $link->id);
        Mail::assertNotSent(AccountPendingDeactivationMail::class, fn($mail) => $mail->user->id === $user->id);
    }

    public function testReactivatesSapeurLinkThatBecameActiveAgainAfterBeingCut(): void
    {
        Mail::fake();
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $this->fakeSapeursActifs(['test' => [1 => '']]);

        $user = User::factory()->create(['admin' => false]);
        $link = $this->linkSapeur($user, $sis, 1, [
            'pending_deactivation_at' => now()->subDays(31),
            'deactivated_at' => now()->subDay(),
        ]);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $link->refresh();
        $this->assertNull($link->deactivated_at);
        $this->assertNull($link->pending_deactivation_at);
        Mail::assertNotSent(SapeurAccessPendingDeactivationMail::class, fn($mail) => $mail->sapeurLink->id === $link->id);
        Mail::assertNotSent(AccountPendingDeactivationMail::class, fn($mail) => $mail->user->id === $user->id);
    }

    public function testDoesNotReactivateCutLinkWhenAnotherAliveLinkAlreadyOccupiesTheSameSapeurId(): void
    {
        Mail::fake();
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $this->fakeSapeursActifs(['test' => [1 => '']]);

        $oldUser = User::factory()->create(['admin' => false]);
        $cutLink = $this->linkSapeur($oldUser, $sis, 1, [
            'pending_deactivation_at' => now()->subDays(31),
            'deactivated_at' => now()->subDay(),
        ]);

        // Sans contrainte unique en base, un autre utilisateur peut déjà occuper
        // ce même sapeur_id (ex. recréé entre-temps par users:sync-sapeurs).
        $newUser = User::factory()->create(['admin' => false]);
        $this->linkSapeur($newUser, $sis, 1);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        // L'ancien lien coupé n'est pas réactivé en doublon.
        $cutLink->refresh();
        $this->assertNotNull($cutLink->deactivated_at);
    }

    public function testDoesNotReactivateSapeurLinkStillInactive(): void
    {
        Mail::fake();
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $this->fakeSapeursActifs(['test' => []]);

        $user = User::factory()->create(['admin' => false]);
        $link = $this->linkSapeur($user, $sis, 1, [
            'pending_deactivation_at' => now()->subDays(31),
            'deactivated_at' => now()->subDay(),
        ]);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $link->refresh();
        $this->assertNotNull($link->deactivated_at);
    }

    public function testDryRunDoesNotReactivateSapeurLink(): void
    {
        Mail::fake();
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $this->fakeSapeursActifs(['test' => [1 => '']]);

        $user = User::factory()->create(['admin' => false]);
        $link = $this->linkSapeur($user, $sis, 1, [
            'pending_deactivation_at' => now()->subDays(31),
            'deactivated_at' => now()->subDay(),
        ]);

        $this->artisan('users:process-deactivation', ['--dry-run' => true])->assertExitCode(0);

        $link->refresh();
        $this->assertNotNull($link->deactivated_at);
    }

    public function testNoNotifyFlagsAccountsAndLinksWithoutSendingAnyEmail(): void
    {
        Mail::fake();
        $sisA = Organisation::firstOrCreate(
            ['org_key' => 'sis_a'],
            ['nom' => 'SIS A', 'abreviation' => 'SA', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $sisB = Organisation::firstOrCreate(
            ['org_key' => 'sis_b'],
            ['nom' => 'SIS B', 'abreviation' => 'SB', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $this->fakeSapeursActifs(['sis_a' => [1 => ''], 'sis_b' => []]);

        // Départ complet : compte sans rôle et sans sapeur actif.
        $orphan = User::factory()->create(['admin' => false]);

        // Départ partiel : reste actif via sisA, mais plus via sisB.
        $partial = User::factory()->create(['admin' => false]);
        $this->linkSapeur($partial, $sisA, 1);
        $staleLink = $this->linkSapeur($partial, $sisB, 2);

        $this->artisan('users:process-deactivation', ['--no-notify' => true])->assertExitCode(0);

        $orphan->refresh();
        $staleLink->refresh();
        $this->assertNotNull($orphan->pending_deactivation_at);
        $this->assertNotNull($staleLink->pending_deactivation_at);
        Mail::assertNothingSent();
    }

    public function testRevokesRoleImmediatelyWhenSapeurBecomesInactiveInThatSis(): void
    {
        Mail::fake();
        $sisA = Organisation::firstOrCreate(
            ['org_key' => 'sis_a'],
            ['nom' => 'SIS A', 'abreviation' => 'SA', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $sisB = Organisation::firstOrCreate(
            ['org_key' => 'sis_b'],
            ['nom' => 'SIS B', 'abreviation' => 'SB', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $this->fakeSapeursActifs(['sis_a' => [], 'sis_b' => [2 => '']]);

        $user = User::factory()->create(['admin' => false]);
        $this->linkSapeur($user, $sisA, 1);
        $this->linkSapeur($user, $sisB, 2);

        $roleA = Role::create(['nom' => 'Role A', 'organisation_id' => $sisA->id]);
        $roleB = Role::create(['nom' => 'Role B', 'organisation_id' => $sisB->id]);
        $userRoleA = UserRole::create(['user_id' => $user->id, 'role_id' => $roleA->id]);
        $userRoleB = UserRole::create(['user_id' => $user->id, 'role_id' => $roleB->id]);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $this->assertDatabaseMissing('user_roles', ['id' => $userRoleA->id]);
        $this->assertDatabaseHas('user_roles', ['id' => $userRoleB->id]);

        $user->refresh();
        $this->assertNull($user->pending_deactivation_at);
    }

    public function testDoesNotRevokeRoleWhenSapeurStillActiveInThatSis(): void
    {
        Mail::fake();
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $this->fakeSapeursActifs(['test' => [1 => '']]);

        $user = User::factory()->create(['admin' => false]);
        $this->linkSapeur($user, $sis, 1);
        $role = Role::create(['nom' => 'Role', 'organisation_id' => $sis->id]);
        $userRole = UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $this->assertDatabaseHas('user_roles', ['id' => $userRole->id]);
    }

    public function testDoesNotRevokeRoleWhenNoSapeurLinkExistsForThatSis(): void
    {
        Mail::fake();
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $this->fakeSapeursActifs(['test' => []]);

        // Rôle attribué sans lien sapeur connu pour ce SIS (ex. compte technique) :
        // aucun signal d'activité fiable, on ne doit pas toucher au rôle.
        $user = User::factory()->create(['admin' => false]);
        $role = Role::create(['nom' => 'Role', 'organisation_id' => $sis->id]);
        $userRole = UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $this->assertDatabaseHas('user_roles', ['id' => $userRole->id]);
    }

    public function testRevokingLastRoleCascadesToAccountFlagInTheSameRun(): void
    {
        Mail::fake();
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $this->fakeSapeursActifs(['test' => []]);

        $user = User::factory()->create(['admin' => false]);
        $this->linkSapeur($user, $sis, 1);
        $role = Role::create(['nom' => 'Seul rôle', 'organisation_id' => $sis->id]);
        $userRole = UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $this->artisan('users:process-deactivation')->assertExitCode(0);

        $this->assertDatabaseMissing('user_roles', ['id' => $userRole->id]);

        $user->refresh();
        $this->assertNotNull($user->pending_deactivation_at);
        Mail::assertSent(AccountPendingDeactivationMail::class, fn($mail) => $mail->user->id === $user->id);
    }

    public function testDryRunDoesNotRevokeRole(): void
    {
        Mail::fake();
        $sis = Organisation::firstOrCreate(
            ['org_key' => 'test'],
            ['nom' => 'Test SIS', 'abreviation' => 'TST', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $this->fakeSapeursActifs(['test' => []]);

        $user = User::factory()->create(['admin' => false]);
        $this->linkSapeur($user, $sis, 1);
        $role = Role::create(['nom' => 'Role', 'organisation_id' => $sis->id]);
        $userRole = UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $this->artisan('users:process-deactivation', ['--dry-run' => true])->assertExitCode(0);

        $this->assertDatabaseHas('user_roles', ['id' => $userRole->id]);
    }

    public function testDoesNotRevokeARoleWhenTheOrganisationsDomaineFetchFailed(): void
    {
        // A single-domaine total failure already aborts the whole command under the old
        // code (before ever reaching role revocation), which would make this test pass for
        // the wrong reason. 'eca' succeeding (trivially) alongside 'sis' failing is what
        // actually exercises the per-organisation skip, since the command does NOT abort
        // early when at least one domaine succeeded.
        $eca = Domaine::where('code', 'eca')->firstOrFail();
        $eca->update(['active_identities_url' => 'https://eca.test/identites-actives']);
        $sis = Organisation::factory()->create();
        $user = User::factory()->create(['admin' => false]);
        $this->linkSapeur($user, $sis, 1); // sapeur_id=1 not present anywhere ('sis' fetch fails)
        $role = Role::create(['nom' => 'Role', 'organisation_id' => $sis->id]);
        $userRole = UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        Http::fake([
            'https://eca.test/identites-actives' => Http::response(['data' => []]),
            '*/api/v2/identites-actives*' => Http::response(['error' => 'boom'], 500),
        ]);
        $this->mock(ExceptionHandler::class, fn($mock) => $mock->shouldReceive('report'));

        $this->artisan('users:process-deactivation')->assertExitCode(1);

        $this->assertDatabaseHas('user_roles', ['id' => $userRole->id]);
    }

    public function testReportsToSentryWhenApiReturnsAnErrorStatus(): void
    {
        Http::fake([
            '*/api/v2/identites-actives*' => Http::response(['error' => 'boom'], 500),
        ]);

        // Deux appels : le fetcher reporte l'échec de récupération du domaine `sis` lui-même,
        // puis la commande reporte séparément le résumé "domaine(s) ignoré(s) ce run" -- utile
        // pour distinguer, dans Bugsink, quelle commande a été affectée par cet échec.
        $this->mock(ExceptionHandler::class, function ($mock) {
            $mock->shouldReceive('report')->twice();
        });

        $exitCode = $this->artisan('users:process-deactivation')->run();

        $this->assertSame(1, $exitCode);
    }

    public function testReportsUnexpectedErrorsAndExitsCleanlyInsteadOfCrashing(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $this->mock(ExceptionHandler::class, function ($mock) {
            $mock->shouldReceive('report')->twice();
        });

        $exitCode = $this->artisan('users:process-deactivation')->run();

        $this->assertSame(1, $exitCode);
    }

    public function testGetSapeursExcludesDeactivatedLinks(): void
    {
        $sisA = Organisation::firstOrCreate(
            ['org_key' => 'sis_a'],
            ['nom' => 'SIS A', 'abreviation' => 'SA', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );
        $sisB = Organisation::firstOrCreate(
            ['org_key' => 'sis_b'],
            ['nom' => 'SIS B', 'abreviation' => 'SB', 'domaine_id' => Domaine::where('code', 'sis')->value('id')]
        );

        $user = User::factory()->create(['admin' => false]);
        $this->linkSapeur($user, $sisA, 1);
        $this->linkSapeur($user, $sisB, 2, ['deactivated_at' => now()]);

        $sapeurs = User::getSapeurs($user->id);

        $this->assertArrayHasKey('sis_a', $sapeurs);
        $this->assertArrayNotHasKey('sis_b', $sapeurs);
    }

    public function testUnknownSisKeyFromApiIsLoggedAndSkipped(): void
    {
        $this->fakeSapeursActifs(['does-not-exist-locally' => [1 => '']]);

        $this->artisan('users:process-deactivation')->assertExitCode(0);
    }
}
