<?php

namespace App\AI6\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class LoginConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $code,
        private readonly int $revision,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'AI6 Anmeldebestätigung – Code-Version '.$this->revision);
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.login-confirmation',
            with: [
                'confirmationCode' => $this->code,
                'confirmationRevision' => $this->revision,
            ],
        );
    }
}
