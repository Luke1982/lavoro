<?php

namespace App\Models;

use App\Models\Traits\HangsOffARecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HangsOffARecord, HasFactory;

    /**
     * The records a document can be filed on. An upload names its record by
     * class, and the controller builds that class: anything not on this list
     * must never get that far.
     */
    public const ATTACHABLE_TO = [
        MaintenanceContract::class,
        Product::class,
        Project::class,
        Quote::class,
        ServiceOrder::class,
        Ticket::class,
    ];

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
