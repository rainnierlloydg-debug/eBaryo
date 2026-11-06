<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class PasswordResetConfirmation extends Mailable
{
    public function __construct(public string $resetUrl) {}

    public function build(): static
    {
        return $this->subject('Confirm your eBaryo password change')
            ->view('emails.password-reset-confirmation');
    }
}
