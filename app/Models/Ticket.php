<?php

namespace App\Models;

use App\Models\Traits\HasCustomFields;
use App\Models\Traits\RemarkableTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    /** @use HasFactory<\Database\Factories\TicketFactory> */
    use HasFactory;
    use RemarkableTrait;
    use HasCustomFields;

    protected $fillable = [
        'asset_id',
        'subject',
        'description',
        'status',
        'priority',
        'closed_on',
        'service_order_id',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withTimestamps();
    }
}
