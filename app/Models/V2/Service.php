<?php

namespace App\Models\V2;

use App\Models\V2\Concerns\Translated;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use Translated;

    protected $table = 'v2_services';

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean'];
}
