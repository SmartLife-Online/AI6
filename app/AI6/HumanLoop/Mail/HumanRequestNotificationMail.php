<?php

namespace App\AI6\HumanLoop\Mail;

use App\AI6\HumanLoop\Models\HumanRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class HumanRequestNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(private readonly HumanRequest $request) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'AI6 Attention: offene Frage im Panel');
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.human-request-notification',
            with: [
                'requestTitle' => $this->request->title,
                'detailUrl' => route('projects.human-requests.show', [
                    $this->request->project_id,
                    $this->request->id,
                ]),
            ],
        );
    }
}
