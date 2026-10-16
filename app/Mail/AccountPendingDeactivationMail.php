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
    public function __construct(public User $user, public string $domaineCode = 'sis')
    {
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): AccountPendingDeactivationMail
    {
        return $this->from(config("domaines.{$this->domaineCode}.mail_from"), config("domaines.{$this->domaineCode}.mail_name"))
            ->subject("Votre compte GestSIS va être désactivé")
            ->text('emails.account_pending_deactivation');
    }
}
