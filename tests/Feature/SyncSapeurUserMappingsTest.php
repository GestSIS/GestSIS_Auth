<?php

namespace Tests\Feature;

use App\Models\Sapeur;
use App\Models\Sis;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncSapeurUserMappingsTest extends TestCase
{
    protected function fakeSapeursEmails(array $data): void
    {
        Http::fake([
            '*/api/v2/sapeurs-emails*' => Http::response(['data' => $data]),
        ]);
    }

    protected function linkSapeur(User $user, Sis $sis, int $sapeurId, array $attributes = []): Sapeur
    {
        Sapeur::insert(array_merge([
            'sapeur_id' => $sapeurId,
            'sis_id' => $sis->id,
            'user_id' => $user->id,
        ], $attributes));

        return Sapeur::where('sapeur_id', $sapeurId)->where('sis_id', $sis->id)->firstOrFail();
    }

    public function testCreatesMissingMappingWhenEmailMatchesAndNoExistingLink(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $user = User::factory()->create(['email' => 'sapeur@example.com']);
        $this->fakeSapeursEmails(['test' => [42 => 'sapeur@example.com']]);

        $this->artisan('users:sync-sapeurs')->assertExitCode(0);

        $this->assertDatabaseHas('sapeurs', [
            'user_id' => $user->id,
            'sis_id' => $sis->id,
            'sapeur_id' => 42,
        ]);
    }

    public function testIgnoresAccountsWithUnverifiedEmail(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        // Un compte non vérifié n'est qu'une revendication de l'email : ne jamais le lier.
        $user = User::factory()->unverified()->create(['email' => 'sapeur@example.com']);
        $this->fakeSapeursEmails(['test' => [42 => 'sapeur@example.com']]);

        $this->artisan('users:sync-sapeurs')->assertExitCode(0);

        $this->assertDatabaseMissing('sapeurs', [
            'user_id' => $user->id,
            'sis_id' => $sis->id,
        ]);
    }

    public function testMatchesEmailCaseInsensitively(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $user = User::factory()->create(['email' => 'Sapeur.Test@Example.com']);
        // Casse différente côté SIS.
        $this->fakeSapeursEmails(['test' => [42 => 'sapeur.test@example.com']]);

        $this->artisan('users:sync-sapeurs')->assertExitCode(0);

        $this->assertDatabaseHas('sapeurs', [
            'user_id' => $user->id,
            'sis_id' => $sis->id,
            'sapeur_id' => 42,
        ]);
    }

    public function testNoOpWhenMappingAlreadyMatches(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $user = User::factory()->create(['email' => 'sapeur@example.com']);
        $this->linkSapeur($user, $sis, 42);
        $this->fakeSapeursEmails(['test' => [42 => 'sapeur@example.com']]);

        $this->mock(ExceptionHandler::class, function ($mock) {
            $mock->shouldNotReceive('report');
        });

        $this->artisan('users:sync-sapeurs')->assertExitCode(0);

        $this->assertSame(1, Sapeur::where('user_id', $user->id)->where('sis_id', $sis->id)->count());
    }

    public function testSkipsWhenNoMatchingUserAccount(): void
    {
        Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $this->fakeSapeursEmails(['test' => [42 => 'inconnu@example.com']]);

        $this->artisan('users:sync-sapeurs')->assertExitCode(0);

        $this->assertDatabaseMissing('sapeurs', ['sapeur_id' => 42]);
    }

    public function testReportsConflictWhenUserAlreadyLinkedToDifferentSapeurInSameSis(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $user = User::factory()->create(['email' => 'sapeur@example.com']);
        $this->linkSapeur($user, $sis, 42);
        $this->fakeSapeursEmails(['test' => [99 => 'sapeur@example.com']]);

        $this->mock(ExceptionHandler::class, function ($mock) {
            $mock->shouldReceive('report')->once();
        });

        $this->artisan('users:sync-sapeurs')->assertExitCode(0);

        // Rien n'a bougé : ni le lien existant, ni de nouveau lien créé.
        $this->assertDatabaseHas('sapeurs', ['user_id' => $user->id, 'sis_id' => $sis->id, 'sapeur_id' => 42]);
        $this->assertDatabaseMissing('sapeurs', ['sapeur_id' => 99]);
    }

    public function testReportsConflictWhenSapeurAlreadyLinkedToDifferentUser(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $oldUser = User::factory()->create(['email' => 'ancien@example.com']);
        $newUser = User::factory()->create(['email' => 'nouveau@example.com']);
        $this->linkSapeur($oldUser, $sis, 42);
        // Le sapeur_id=42 a maintenant l'email de newUser (changement d'email côté SIS).
        $this->fakeSapeursEmails(['test' => [42 => 'nouveau@example.com']]);

        $this->mock(ExceptionHandler::class, function ($mock) {
            $mock->shouldReceive('report')->once();
        });

        $this->artisan('users:sync-sapeurs')->assertExitCode(0);

        $this->assertDatabaseHas('sapeurs', ['user_id' => $oldUser->id, 'sis_id' => $sis->id, 'sapeur_id' => 42]);
        $this->assertDatabaseMissing('sapeurs', ['user_id' => $newUser->id]);
    }

    public function testCreatesNewLinkAndPreservesOldDeadLinkWhenUserHadADifferentDeactivatedSapeurInSameSis(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $user = User::factory()->create(['email' => 'sapeur@example.com']);
        $deadLink = $this->linkSapeur($user, $sis, 42, [
            'deactivated_at' => now()->subDay(),
            'pending_deactivation_at' => now()->subDays(31),
        ]);
        // Le sapeur revient sous un nouvel id (ex. nouvel enregistrement côté SIS).
        $this->fakeSapeursEmails(['test' => [99 => 'sapeur@example.com']]);

        $this->mock(ExceptionHandler::class, function ($mock) {
            $mock->shouldNotReceive('report');
        });

        $this->artisan('users:sync-sapeurs')->assertExitCode(0);

        // L'ancien lien mort reste intact (historique), un nouveau lien actif est créé.
        $deadLink->refresh();
        $this->assertSame(42, $deadLink->sapeur_id);
        $this->assertNotNull($deadLink->deactivated_at);
        $this->assertDatabaseHas('sapeurs', [
            'user_id' => $user->id,
            'sis_id' => $sis->id,
            'sapeur_id' => 99,
            'deactivated_at' => null,
        ]);
        $this->assertSame(2, Sapeur::where('user_id', $user->id)->where('sis_id', $sis->id)->count());
    }

    public function testCreatesNewLinkAndPreservesOldDeadLinkWhenSapeurWasLinkedToADifferentDeactivatedUser(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $oldUser = User::factory()->create(['email' => 'ancien@example.com']);
        $newUser = User::factory()->create(['email' => 'nouveau@example.com']);
        $deadLink = $this->linkSapeur($oldUser, $sis, 42, [
            'deactivated_at' => now()->subDay(),
            'pending_deactivation_at' => now()->subDays(31),
        ]);
        // Le sapeur_id=42 a maintenant l'email de newUser (changement d'email côté SIS).
        $this->fakeSapeursEmails(['test' => [42 => 'nouveau@example.com']]);

        $this->mock(ExceptionHandler::class, function ($mock) {
            $mock->shouldNotReceive('report');
        });

        $this->artisan('users:sync-sapeurs')->assertExitCode(0);

        // L'ancien lien mort (ancien utilisateur) reste intact...
        $deadLink->refresh();
        $this->assertSame($oldUser->id, $deadLink->user_id);
        $this->assertNotNull($deadLink->deactivated_at);
        // ...et un nouveau lien actif relie le nouvel utilisateur au même sapeur_id.
        $this->assertDatabaseHas('sapeurs', [
            'user_id' => $newUser->id,
            'sis_id' => $sis->id,
            'sapeur_id' => 42,
            'deactivated_at' => null,
        ]);
    }

    public function testReactivatesExactSameDeadLinkWhenItBecomesActiveAgain(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $user = User::factory()->create(['email' => 'sapeur@example.com']);
        $deadLink = $this->linkSapeur($user, $sis, 42, [
            'deactivated_at' => now()->subDay(),
            'pending_deactivation_at' => now()->subDays(31),
        ]);
        // Exactement le même sapeur_id redevient actif pour le même utilisateur.
        $this->fakeSapeursEmails(['test' => [42 => 'sapeur@example.com']]);

        $this->mock(ExceptionHandler::class, function ($mock) {
            $mock->shouldNotReceive('report');
        });

        $this->artisan('users:sync-sapeurs')->assertExitCode(0);

        // Même ligne réactivée (id conservé), pas de doublon créé.
        $deadLink->refresh();
        $this->assertNull($deadLink->deactivated_at);
        $this->assertNull($deadLink->pending_deactivation_at);
        $this->assertSame(1, Sapeur::where('user_id', $user->id)->where('sis_id', $sis->id)->count());
    }

    public function testHandlesTwoSapeursSharingEmailInSameSisWithinOneRun(): void
    {
        $sis = Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $user = User::factory()->create(['email' => 'partage@example.com']);
        // Deux sapeur_id distincts partagent le même email dans le même SIS.
        $this->fakeSapeursEmails(['test' => [10 => 'partage@example.com', 20 => 'partage@example.com']]);

        $this->mock(ExceptionHandler::class, function ($mock) {
            $mock->shouldReceive('report')->once();
        });

        $this->artisan('users:sync-sapeurs')->assertExitCode(0);

        // Un seul des deux a pu être lié, l'autre doit être détecté en conflit (pas les deux créés).
        $this->assertSame(1, Sapeur::where('user_id', $user->id)->where('sis_id', $sis->id)->count());
    }

    public function testDryRunDoesNotCreateMapping(): void
    {
        Sis::firstOrCreate(['api_key' => 'test'], ['nom' => 'Test SIS', 'abreviation' => 'TST']);
        $user = User::factory()->create(['email' => 'sapeur@example.com']);
        $this->fakeSapeursEmails(['test' => [42 => 'sapeur@example.com']]);

        $this->artisan('users:sync-sapeurs', ['--dry-run' => true])->assertExitCode(0);

        $this->assertDatabaseMissing('sapeurs', ['user_id' => $user->id]);
    }

    public function testReportsToSentryWhenApiReturnsAnErrorStatus(): void
    {
        Http::fake([
            '*/api/v2/sapeurs-emails*' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->mock(ExceptionHandler::class, function ($mock) {
            $mock->shouldReceive('report')->once();
        });

        $exitCode = $this->artisan('users:sync-sapeurs')->run();

        $this->assertSame(1, $exitCode);
    }

    public function testReportsUnexpectedErrorsAndExitsCleanlyInsteadOfCrashing(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $this->mock(ExceptionHandler::class, function ($mock) {
            $mock->shouldReceive('report')->once();
        });

        $exitCode = $this->artisan('users:sync-sapeurs')->run();

        $this->assertSame(1, $exitCode);
    }
}
