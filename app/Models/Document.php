<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'path',
        'size',
        'title',
        'document_category_id',
        'user_id',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(DocumentCategory::class, 'document_category_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
