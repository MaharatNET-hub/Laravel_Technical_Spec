<?php

namespace App\Mail\V2;

use App\Models\V2\Submission;
use App\V2\Site;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the client: we have your submittal, here is the tracking code. */
class SubmissionReceived extends Mailable
{
    public function __construct(public Submission $s) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[{$this->s->code}] " . ($this->s->locale === 'ar' ? 'استلمنا تقديمك' : 'Submittal received') . ' — ' . Site::name());
    }

    public function content(): Content
    {
        return new Content(view: 'v2.mail.received');
    }
}
