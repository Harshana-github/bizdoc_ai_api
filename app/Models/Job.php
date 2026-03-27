<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Job extends Model
{
    protected $fillable = [
        'code',
        'customer_id',
        'is_report_generated'
    ];

    public function images()
    {
        return $this->hasMany(JobImage::class);
    }
}
