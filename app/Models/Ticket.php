<?php

namespace App\Models;

use App\Models\Traits\HasActivities;
use App\Models\Traits\HasCustomFields;
use App\Models\Traits\RemarkableTrait;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    use HasActivities;
    use HasCustomFields;

    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    use RemarkableTrait;

    protected $fillable = [
        'asset_id',
        'subject',
        'description',
        'status',
        'priority',
        'status_code',
        'closed_on',
        'closed_by_id',
        'created_by_id',
        'service_order_id',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id')->withTrashed();
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withPivot(['main'])
            ->withTimestamps();
    }

    public function documents()
    {
        return $this->morphToMany(Document::class, 'documentable')
            ->withTimestamps();
    }
}
