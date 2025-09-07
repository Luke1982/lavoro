<?php

namespace App\Models;

use App\Models\Traits\RemarkableTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceOrder extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceOrderFactory> */
    use HasFactory;
    use RemarkableTrait;

    protected $fillable = [
        'description',
        'customer_id',
        'closed_on',
        'signed_by',
        'signature_base64',
        'sent',
    ];

    protected $casts = [
        'sent' => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function serviceJobs()
    {
        return $this->hasMany(ServiceJob::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function materials()
    {
        return $this->morphToMany(
            Material::class,
            'materiable',
        )->withPivot(
            'quantity',
            'material_role_id',
            'id'
        )->withTimestamps();
    }

    public function events()
    {
        return $this->morphToMany(Event::class, 'eventable');
    }
}
