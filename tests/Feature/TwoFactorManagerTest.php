<?php

namespace Tests\Feature;

use App\Auth\Factors\EnrollableFactor;
use App\Auth\Factors\TwoFactorException;
use App\Auth\TwoFactorManager;
use App\Models\TwoFactorMethod;
use App\Models\User;
use OTPHP\TOTP;
use Tests\TestCase;

/**
 * Le domaine 2FA (TwoFactorManager et ses facteurs), testé sans passer par HTTP.
 */
class TwoFactorManagerTest extends TestCase
{
    private function manager(): TwoFactorManager
    {
        return app(TwoFactorManager::class);
    }

    public function testOnlyConfirmedMethodsCountAndAreListedInAStableOrder(): void
    {
        $user = User::factory()->create();
        TwoFactorMethod::factory()->webauthn()->create(['user_id' => $user->id]);
        TwoFactorMethod::factory()->totp()->unconfirmed()->create(['user_id' => $user->id]);

        $this->assertTrue($user->hasTwoFactorEnabled());
        $this->assertSame(['webauthn'], $this->manager()->availableMethods($user));

        TwoFactorMethod::where('user_id', $user->id)->update(['confirmed_at' => now()]);
        $this->assertSame(['totp', 'webauthn'], $this->manager()->availableMethods($user));
    }

    public function testRecoveryCodesAloneDoNotCountAsTwoFactor(): void
    {
        $user = User::factory()->create();
        $this->manager()->recoveryCodes()->regenerate($user);

        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertSame([], $this->manager()->availableMethods($user));
    }

    public function testAnUnconfirmedTotpIsNeverAcceptedAsASecondFactor(): void
    {
        $user = User::factory()->create();
        $secret = TOTP::generate()->getSecret();
        TwoFactorMethod::factory()->totp($secret)->unconfirmed()->create(['user_id' => $user->id]);

        $this->assertFalse($this->manager()->verifyCode($user, $this->totpCode($secret)));
    }

    public function testATotpCodeIsConsumedForItsWindow(): void
    {
        $user = User::factory()->create();
        $secret = TOTP::generate()->getSecret();
        TwoFactorMethod::factory()->totp($secret)->create(['user_id' => $user->id]);
        $code = $this->totpCode($secret);

        $this->assertTrue($this->manager()->verifyCode($user, $code));
        $this->assertFalse($this->manager()->verifyCode($user, $code));

        $this->nextTotpWindow();
        $this->assertTrue($this->manager()->verifyCode($user, $this->totpCode($secret)));
    }

    public function testARecoveryCodeIsAcceptedOnce(): void
    {
        $user = User::factory()->create();
        TwoFactorMethod::factory()->create(['user_id' => $user->id]);
        $code = $this->manager()->recoveryCodes()->regenerate($user)[0];

        $this->assertTrue($this->manager()->verifyCode($user, $code));
        $this->assertFalse($this->manager()->verifyCode($user, $code));
    }

    public function testRestartingTotpEnrollmentReplacesThePendingSecret(): void
    {
        $user = User::factory()->create();

        $first = $this->manager()->totp()->beginEnrollment($user)['secret'];
        $second = $this->manager()->totp()->beginEnrollment($user)['secret'];

        $this->assertNotSame($first, $second);
        $this->assertSame(1, $user->twoFactorMethods()->count());
        $this->assertSame($second, $this->totpSecretOf($user));
    }

    public function testTheFirstMethodIssuesRecoveryCodesButNotTheNextOnes(): void
    {
        $user = User::factory()->create();
        $secret = $this->manager()->totp()->beginEnrollment($user)['secret'];

        $first = $this->manager()->enroll($user, $this->manager()->totp(), ['code' => $this->totpCode($secret)], null);
        $this->assertCount(8, $first['recoveryCodes']);
        $this->assertTrue($this->hasConfirmedTotp($user));

        $codesAfterFirst = $user->twoFactorRecoveryCodes()->pluck('code_hash')->all();
        $secondKey = new class implements EnrollableFactor {
            public function type(): string { return TwoFactorMethod::TYPE_WEBAUTHN; }
            public function isActiveFor(User $user): bool { return false; }
            public function createChallenge(User $user): ?array { return null; }
            public function verify(User $user, mixed $proof): bool { return false; }
            public function revoke(User $user, ?int $methodId = null): void {}
            public function beginEnrollment(User $user): array { return []; }
            public function completeEnrollment(User $user, array $input): TwoFactorMethod
            {
                return TwoFactorMethod::factory()->webauthn()->create(['user_id' => $user->id]);
            }
        };

        $second = $this->manager()->enroll($user, $secondKey, [], null);

        $this->assertNull($second['recoveryCodes']);
        $this->assertSame($codesAfterFirst, $user->twoFactorRecoveryCodes()->pluck('code_hash')->all());
    }

    public function testConfirmingTotpWithoutAPendingEnrollmentFails(): void
    {
        $user = User::factory()->create();

        $this->expectException(TwoFactorException::class);
        $this->manager()->enroll($user, $this->manager()->totp(), ['code' => '123456'], null);
    }

    public function testRemovingTheLastMethodPurgesRecoveryCodesButNotWhileAnotherRemains(): void
    {
        $user = User::factory()->create();
        TwoFactorMethod::factory()->create(['user_id' => $user->id]);
        $key = TwoFactorMethod::factory()->webauthn()->create(['user_id' => $user->id]);
        $this->manager()->recoveryCodes()->regenerate($user);

        $this->manager()->revoke($user, $this->manager()->webauthn(), $key->id);
        $this->assertSame(8, $user->twoFactorRecoveryCodes()->count());

        $this->manager()->revoke($user, $this->manager()->totp());
        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertSame(0, $user->twoFactorRecoveryCodes()->count());
    }

    public function testRevokingOneWebauthnKeyKeepsTheOthers(): void
    {
        $user = User::factory()->create();
        $removed = TwoFactorMethod::factory()->webauthn()->create(['user_id' => $user->id]);
        $kept = TwoFactorMethod::factory()->webauthn()->create(['user_id' => $user->id]);

        $this->manager()->revoke($user, $this->manager()->webauthn(), $removed->id);

        $this->assertDatabaseMissing('two_factor_methods', ['id' => $removed->id]);
        $this->assertDatabaseHas('two_factor_methods', ['id' => $kept->id]);
    }

    public function testAWebauthnLoginChallengeRequiresAConfirmedKey(): void
    {
        $user = User::factory()->create();
        TwoFactorMethod::factory()->webauthn()->unconfirmed()->create(['user_id' => $user->id]);

        $this->expectException(TwoFactorException::class);
        $this->manager()->webauthn()->createChallenge($user);
    }
}
