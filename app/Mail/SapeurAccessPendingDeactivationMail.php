<?php

namespace App\Mail;

use App\Models\Domaine;
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
    public function __construct(public User $user, public Sapeur $sapeurLink, public string $domaineCode = 'sis')
    {
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): SapeurAccessPendingDeactivationMail
    {
        $domaine = Domaine::where('code', $this->domaineCode)->firstOrFail();

        return $this->from($domaine->mail_from, $domaine->mail_name)
            ->subject("Votre accès à {$this->sapeurLink->organisation->nom} va être désactivé")
            ->text('emails.sapeur_access_pending_deactivation');
    }
}
