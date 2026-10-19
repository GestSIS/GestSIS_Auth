<?php

namespace App\Mail;

use App\Models\Domaine;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ConfirmationEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(public User $user, public string $code, public string $domaineCode = 'sis')
    {
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): ConfirmationEmail
    {
        $domaine = Domaine::where('code', $this->domaineCode)->firstOrFail();

        return $this->from($domaine->mail_from, $domaine->mail_name)
            ->subject("Inscription à GestSIS")
            ->text('emails.confirmation_email');
    }
}
