<?php

namespace Tests\Feature;

use App\Mail\TwoFactorReminderMail;
use App\Models\TwoFactorPolicy;
use App\Models\User;
use App\Models\WebauthnCredential;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendTwoFactorRemindersTest extends TestCase
{
    public function testDoesNothingWithoutAnActivePolicy(): void
    {
        Mail::fake();
        User::factory()->create();

        $this->artisan('users:2fa-reminder')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function testSendsAReminderToAPendingUserWithinTheThirtyDayWindow(): void
    {
        Mail::fake();
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->addDays(25);
        $policy->save();

        $user = User::factory()->create();

        $this->artisan('users:2fa-reminder')->assertSuccessful();

        Mail::assertSent(TwoFactorReminderMail::class, fn($mail) => $mail->hasTo($user->email));
        $this->assertNotNull($user->fresh()->two_factor_reminder_sent_at);
    }

    public function testDoesNotSendAgainForTheSameThresholdOnceAlreadyNotified(): void
    {
        Mail::fake();
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->addDays(25);
        $policy->save();

        $user = User::factory()->create(['two_factor_reminder_sent_at' => now()]);

        $this->artisan('users:2fa-reminder')->assertSuccessful();

        // La base de dev contient déjà des comptes de démo (admin@gestsis.ch,
        // demo@gestsis.ch) : on vérifie que CE user précis n'a pas reçu de
        // rappel plutôt qu'assertNothingSent(), qui échouerait sur ces comptes
        // préexistants sans rapport avec ce test.
        Mail::assertNotSent(TwoFactorReminderMail::class, fn($mail) => $mail->hasTo($user->email));
    }

    public function testSkipsUsersWhoAlreadyHaveTwoFactorEnabled(): void
    {
        Mail::fake();
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->addDays(5);
        $policy->save();

        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

        $this->artisan('users:2fa-reminder')->assertSuccessful();

        Mail::assertNotSent(TwoFactorReminderMail::class, fn($mail) => $mail->hasTo($user->email));
    }

    public function testSkipsUsersProtectedOnlyByAWebauthnKey(): void
    {
        Mail::fake();
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->addDays(5);
        $policy->save();

        $user = User::factory()->create();
        WebauthnCredential::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode('cred-reminder'),
            'public_key' => base64_encode('pk-reminder'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Clé',
        ]);

        $this->artisan('users:2fa-reminder')->assertSuccessful();

        Mail::assertNotSent(TwoFactorReminderMail::class, fn($mail) => $mail->hasTo($user->email));
    }

    public function testAFailedSendDoesNotBlockTheOtherReminders(): void
    {
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->addDays(5);
        $policy->save();

        $failing = User::factory()->create();
        $other = User::factory()->create();

        Mail::shouldReceive('to')->andReturnUsing(function (string $email) use ($failing) {
            $pendingMail = \Mockery::mock();
            $pendingMail->shouldReceive('send')->andReturnUsing(function () use ($email, $failing) {
                if ($email === $failing->email) {
                    throw new \RuntimeException('SMTP indisponible');
                }
            });

            return $pendingMail;
        });

        $this->artisan('users:2fa-reminder')->assertFailed();

        $this->assertNull($failing->fresh()->two_factor_reminder_sent_at);
        $this->assertNotNull($other->fresh()->two_factor_reminder_sent_at);
    }

    public function testSkipsExemptUsers(): void
    {
        Mail::fake();
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->addDays(5);
        $policy->save();

        $user = User::factory()->create(['two_factor_exempt' => true]);

        $this->artisan('users:2fa-reminder')->assertSuccessful();

        Mail::assertNotSent(TwoFactorReminderMail::class, fn($mail) => $mail->hasTo($user->email));
    }

    public function testDoesNotSendWhenTheEnforcementDateIsMoreThanThirtyDaysAway(): void
    {
        Mail::fake();
        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = now()->addDays(60);
        $policy->save();

        User::factory()->create();

        $this->artisan('users:2fa-reminder')->assertSuccessful();

        Mail::assertNothingSent();
    }
}
