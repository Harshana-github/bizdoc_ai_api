<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcrProcess extends Model
{
    protected $fillable = [
        'original_name',
        'stored_path',
        'mime_type',
        'user_id',
    ];
}
