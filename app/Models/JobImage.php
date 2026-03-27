<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobImage extends Model
{
    protected $fillable = [
        'job_id',
        'image_path',
        'area',
        'stage',
        'quality_score'
    ];

    public function job()
    {
        return $this->belongsTo(Job::class);
    }
}
