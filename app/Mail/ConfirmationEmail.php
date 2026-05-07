<?php

namespace App\Mail;

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
    public function __construct(public User $user, public string $plainToken)
    {
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): ConfirmationEmail
    {
        return $this->from('test@gestsis.ch', 'GestSIS')
            ->subject("Inscription à GestSIS")
            ->text('emails.confirmation_email');
    }
}
