<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $connection = 'central';

    protected $table = 'invoices';

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'issued_on' => 'date',
    ];

    public function lines()
    {
        return $this->hasMany(InvoiceLine::class);
    }

    protected $fillable = ['number', 'tenant_id', 'period_start', 'period_end', 'issued_on', 'subtotal_cents', 'discount_cents', 'total_cents'];
}
