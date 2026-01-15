<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OcrResult extends Model
{
    use HasFactory;

    protected $table = 'ocr_results';

    protected $fillable = [
        'file_path',
        'data',
        'user_id',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
