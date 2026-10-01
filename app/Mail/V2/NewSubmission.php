<?php

namespace App\Mail\V2;

use App\Models\V2\Submission;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the office: a new submittal arrived through the website. */
class NewSubmission extends Mailable
{
    public function __construct(public Submission $s) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "New submittal {$this->s->code}: {$this->s->project_name}", replyTo: [$this->s->client_email]);
    }

    public function content(): Content
    {
        return new Content(view: 'v2.mail.new');
    }
}
