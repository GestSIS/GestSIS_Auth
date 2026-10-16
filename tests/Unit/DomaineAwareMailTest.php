<?php

namespace Tests\Unit;

use App\Mail\AccountPendingDeactivationMail;
use App\Mail\ResetPassword;
use App\Mail\SapeurAccessPendingDeactivationMail;
use App\Mail\TwoFactorReminderMail;
use App\Models\Sapeur;
use App\Models\Sis;
use App\Models\User;
use Tests\TestCase;

class DomaineAwareMailTest extends TestCase
{
    public function testResetPasswordMailUsesSisBrandingForASisAccount(): void
    {
        $mail = new ResetPassword('token', domaineCode: 'sis')->build();

        $this->assertSame(config('domaines.sis.mail_from'), $mail->from[0]['address']);
        $this->assertSame(config('domaines.sis.mail_name'), $mail->from[0]['name']);
    }

    public function testResetPasswordMailDefaultsToSisBrandingWhenDomaineCodeIsOmitted(): void
    {
        $mail = new ResetPassword('token')->build();

        $this->assertSame(config('domaines.sis.mail_from'), $mail->from[0]['address']);
        $this->assertSame(config('domaines.sis.mail_name'), $mail->from[0]['name']);
    }

    public function testAccountPendingDeactivationMailUsesEcaBranding(): void
    {
        $user = User::factory()->create();

        $mail = new AccountPendingDeactivationMail($user, domaineCode: 'eca')->build();

        $this->assertSame(config('domaines.eca.mail_from'), $mail->from[0]['address']);
        $this->assertSame(config('domaines.eca.mail_name'), $mail->from[0]['name']);
    }

    public function testTwoFactorReminderMailUsesEcaBranding(): void
    {
        $user = User::factory()->create();

        $mail = new TwoFactorReminderMail($user, now()->addDays(7), domaineCode: 'eca')->build();

        $this->assertSame(config('domaines.eca.mail_from'), $mail->from[0]['address']);
        $this->assertSame(config('domaines.eca.mail_name'), $mail->from[0]['name']);
    }

    public function testSapeurAccessPendingDeactivationMailUsesEcaBranding(): void
    {
        $sis = Sis::factory()->create();
        $user = User::factory()->create();
        Sapeur::insert(['sapeur_id' => 1, 'sis_id' => $sis->id, 'user_id' => $user->id]);
        $link = Sapeur::with('sis')->where('sis_id', $sis->id)->where('sapeur_id', 1)->firstOrFail();

        $mail = new SapeurAccessPendingDeactivationMail($user, $link, domaineCode: 'eca')->build();

        $this->assertSame(config('domaines.eca.mail_from'), $mail->from[0]['address']);
        $this->assertSame(config('domaines.eca.mail_name'), $mail->from[0]['name']);
    }
}
