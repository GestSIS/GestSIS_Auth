<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccountPendingDeactivationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(public User $user)
    {
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): AccountPendingDeactivationMail
    {
        return $this->from('test@gestsis.ch', 'GestSIS')
            ->subject("Votre compte GestSIS va être désactivé")
            ->text('emails.account_pending_deactivation');
    }
}
