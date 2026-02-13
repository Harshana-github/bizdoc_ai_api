<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Template extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_type_id',
        'file_name',
    ];

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }
}
