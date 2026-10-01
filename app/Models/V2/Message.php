<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $table = 'v2_messages';

    protected $guarded = ['id'];

    protected $casts = ['read_at' => 'datetime'];
}
