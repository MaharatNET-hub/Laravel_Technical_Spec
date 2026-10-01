<?php

namespace App\Models\V2;

use App\Models\V2\Concerns\Translated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Project extends Model
{
    use Translated;

    protected $table = 'v2_projects';

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean', 'featured' => 'boolean'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function imageUrl(): ?string
    {
        return $this->image && Storage::disk('public')->exists($this->image) ? route('v2.media', ['path' => $this->image]) : null;
    }
}
