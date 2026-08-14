<?php

namespace App\Mail;

use App\Models\Sapeur;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SapeurAccessPendingDeactivationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(public User $user, public Sapeur $sapeurLink)
    {
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): SapeurAccessPendingDeactivationMail
    {
        return $this->from('test@gestsis.ch', 'GestSIS')
            ->subject("Votre accès à {$this->sapeurLink->sis->nom} va être désactivé")
            ->text('emails.sapeur_access_pending_deactivation');
    }
}
