<?php

namespace App\Mail;

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
    public function __construct(public string $token)
    {
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): ResetPassword
    {
        return $this->from('test@gestsis.ch', 'GestSIS')
            ->subject("Réinitialisation du mot de passe GestSIS")
            ->text('emails.reset_password');
    }
}
