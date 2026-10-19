<?php

namespace App\Mail;

use App\Models\Domaine;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPassword extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(public string $token, public string $domaineCode = 'sis')
    {
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): ResetPassword
    {
        $domaine = Domaine::where('code', $this->domaineCode)->firstOrFail();

        return $this->from($domaine->mail_from, $domaine->mail_name)
            ->subject("Réinitialisation du mot de passe GestSIS")
            ->text('emails.reset_password', ['appUrl' => $domaine->app_url]);
    }
}
