<?php

namespace App\Models\Central;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $connection = 'central';

    protected $table = 'invoices';

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'issued_on' => 'date',
        'due_on' => 'date',
        'mailed_at' => 'datetime',
        'collected_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    protected $fillable = ['number', 'tenant_id', 'period_start', 'period_end', 'issued_on', 'due_on', 'subtotal_cents', 'discount_cents', 'total_cents', 'vat_percent', 'vat_cents', 'gross_cents', 'mailed_at', 'mail_error', 'collected_at', 'collection_batch'];
}
