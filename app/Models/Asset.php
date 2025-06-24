<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    /** @use HasFactory<\Database\Factories\AssetsFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'customer_id',
        'serial_number',
        'next_service_date',
        'status',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function openTickets()
    {
        return $this->hasMany(Ticket::class)->where('status', 'Open');
    }

    public function pendingTickets()
    {
        return $this->hasMany(Ticket::class)->where('status', 'In behandeling');
    }

    public function closedTickets()
    {
        return $this->hasMany(Ticket::class)->where('status', 'Gesloten');
    }
}
