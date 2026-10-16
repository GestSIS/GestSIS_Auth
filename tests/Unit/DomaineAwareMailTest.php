<?php

namespace Tests\Unit;

use App\Mail\AccountPendingDeactivationMail;
use App\Mail\ResetPassword;
use App\Mail\SapeurAccessPendingDeactivationMail;
use App\Mail\TwoFactorReminderMail;
use App\Models\Domaine;
use App\Models\Sapeur;
use App\Models\Organisation;
use App\Models\User;
use Tests\TestCase;

class DomaineAwareMailTest extends TestCase
{
    public function testResetPasswordMailUsesSisBrandingForASisAccount(): void
    {
        $mail = new ResetPassword('token', domaineCode: 'sis')->build();

        $sis = Domaine::where('code', 'sis')->firstOrFail();
        $this->assertSame($sis->mail_from, $mail->from[0]['address']);
        $this->assertSame($sis->mail_name, $mail->from[0]['name']);
    }

    public function testResetPasswordMailDefaultsToSisBrandingWhenDomaineCodeIsOmitted(): void
    {
        $mail = new ResetPassword('token')->build();

        $sis = Domaine::where('code', 'sis')->firstOrFail();
        $this->assertSame($sis->mail_from, $mail->from[0]['address']);
        $this->assertSame($sis->mail_name, $mail->from[0]['name']);
    }

    public function testAccountPendingDeactivationMailUsesEcaBranding(): void
    {
        $user = User::factory()->create();

        $mail = new AccountPendingDeactivationMail($user, domaineCode: 'eca')->build();

        $eca = Domaine::where('code', 'eca')->firstOrFail();
        $this->assertSame($eca->mail_from, $mail->from[0]['address']);
        $this->assertSame($eca->mail_name, $mail->from[0]['name']);
    }

    public function testTwoFactorReminderMailUsesEcaBranding(): void
    {
        $user = User::factory()->create();

        $mail = new TwoFactorReminderMail($user, now()->addDays(7), domaineCode: 'eca')->build();

        $eca = Domaine::where('code', 'eca')->firstOrFail();
        $this->assertSame($eca->mail_from, $mail->from[0]['address']);
        $this->assertSame($eca->mail_name, $mail->from[0]['name']);
    }

    public function testSapeurAccessPendingDeactivationMailUsesEcaBranding(): void
    {
        $sis = Organisation::factory()->create();
        $user = User::factory()->create();
        Sapeur::insert(['sapeur_id' => 1, 'organisation_id' => $sis->id, 'user_id' => $user->id]);
        $link = Sapeur::with('organisation')->where('organisation_id', $sis->id)->where('sapeur_id', 1)->firstOrFail();

        $mail = new SapeurAccessPendingDeactivationMail($user, $link, domaineCode: 'eca')->build();

        $eca = Domaine::where('code', 'eca')->firstOrFail();
        $this->assertSame($eca->mail_from, $mail->from[0]['address']);
        $this->assertSame($eca->mail_name, $mail->from[0]['name']);
    }
}
