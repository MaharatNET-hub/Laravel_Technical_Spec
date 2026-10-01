<?php

namespace App\Models\V2;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    protected $table = 'v2_activities';

    protected $guarded = ['id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function submission()
    {
        return $this->belongsTo(Submission::class);
    }

    public static function log(?Submission $s, string $action, ?string $detail = null): void
    {
        self::create(['submission_id' => $s?->id, 'user_id' => auth()->id(), 'action' => $action, 'detail' => $detail]);
    }
}
