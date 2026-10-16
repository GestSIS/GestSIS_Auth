<?php

namespace App\Mail;

use App\Models\Domaine;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TwoFactorReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public \Carbon\CarbonInterface $enforcedAt, public string $domaineCode = 'sis')
    {
    }

    public function build(): TwoFactorReminderMail
    {
        $domaine = Domaine::where('code', $this->domaineCode)->firstOrFail();

        return $this->from($domaine->mail_from, $domaine->mail_name)
            ->subject("Activez le 2FA sur votre compte GestSIS avant le {$this->enforcedAt->format('d.m.Y')}")
            ->text('emails.two_factor_reminder');
    }
}
