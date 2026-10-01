<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Submission extends Model
{
    protected $table = 'v2_submissions';

    protected $guarded = ['id'];

    protected $casts = ['analysed_at' => 'datetime', 'issued_at' => 'datetime', 'emailed_at' => 'datetime'];

    public const STATUSES = ['uploading', 'received', 'analysing', 'review', 'issued', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (self $s) {
            do {
                $s->code = strtoupper(Str::random(4) . '-' . Str::random(6));
            } while (self::where('code', $s->code)->exists());
        });
        static::deleting(fn (self $s) => \Illuminate\Support\Facades\File::deleteDirectory($s->dir()));
    }

    public function activities()
    {
        return $this->hasMany(Activity::class)->latest();
    }

    /** Private folder with the original PDF and the review files (never web-accessible). */
    public function dir(): string
    {
        return storage_path('app/v2/submissions/' . $this->id);
    }

    public function originalPath(): string
    {
        return $this->dir() . '/original.pdf';
    }

    public function hasOriginal(): bool
    {
        return is_file($this->originalPath());
    }

    /** The current issued/draft PDF of this submission's review, if any. */
    public function review(): ?array
    {
        $f = $this->dir() . '/review.json';

        return is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    }

    public function outputPath(): ?string
    {
        $r = $this->review();
        $f = $r ? $this->dir() . '/out/' . $r['pdfName'] : null;

        return $f && is_file($f) ? $f : null;
    }

    public function sizeLabel(): string
    {
        $b = (int) $this->file_size;

        return $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'issued' => 'ok', 'review' => 'accent', 'analysing' => 'warn', 'received' => 'warn', 'archived' => 'mute', default => 'mute',
        };
    }
}
