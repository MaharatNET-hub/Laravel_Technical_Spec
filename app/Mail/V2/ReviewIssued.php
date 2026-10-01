<?php

namespace App\Mail\V2;

use App\Models\V2\Submission;
use App\V2\Site;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the client: the reviewed submittal (attached when small enough, otherwise a download link). */
class ReviewIssued extends Mailable
{
    public bool $attached;

    public function __construct(public Submission $s, public string $note = '')
    {
        $f = $s->outputPath();
        $this->attached = $f !== null && filesize($f) <= config('v2.mail_attach_mb', 8) * 1048576;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[{$this->s->code}] " . ($this->s->locale === 'ar' ? 'نتيجة مراجعة التقديم' : 'Submittal review') . ': ' . ($this->s->decision ?? '') . ' — ' . Site::name());
    }

    public function content(): Content
    {
        return new Content(view: 'v2.mail.issued');
    }

    public function attachments(): array
    {
        $f = $this->s->outputPath();

        return $this->attached && $f ? [Attachment::fromPath($f)->as(basename($f))->withMime('application/pdf')] : [];
    }
}
