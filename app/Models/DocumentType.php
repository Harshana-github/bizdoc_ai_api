<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DocumentType extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name_en',
        'name_ja',
    ];

    public function templates()
    {
        return $this->hasMany(Template::class);
    }
}
