<?php

namespace App\Models\Central;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $connection = 'central';

    protected $table = 'invoices';

    protected $appends = ['left_the_building'];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'issued_on' => 'date',
        'due_on' => 'date',
        'mailed_at' => 'datetime',
        'collected_at' => 'datetime',
        'booked_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /** The invoice this credit note cancels. */
    public function credits()
    {
        return $this->belongsTo(Invoice::class, 'credits_invoice_id');
    }

    /** The credit note that cancelled this invoice. */
    public function creditedBy()
    {
        return $this->hasOne(Invoice::class, 'credits_invoice_id');
    }

    /**
     * Whether the customer may already have it: mailed, collected or in the
     * bookkeeping. From then on the way back is a credit note.
     *
     * A booking that failed counts too: whether it reached e-Boekhouden is not
     * certain when the answer got lost. Deleted, and its number handed out
     * again after a reset, the next invoice would be taken for it.
     */
    public function hasLeftTheBuilding(): bool
    {
        return $this->mailed_at || $this->collected_at || $this->booked_at || $this->booking_error !== null;
    }

    /** For the screen, so it does not keep its own copy of the rule. */
    protected function leftTheBuilding(): Attribute
    {
        return Attribute::get(fn () => $this->hasLeftTheBuilding());
    }

    protected $fillable = ['number', 'tenant_id', 'tenant_name', 'period_start', 'period_end', 'issued_on', 'due_on', 'subtotal_cents', 'discount_cents', 'total_cents', 'vat_percent', 'vat_cents', 'gross_cents', 'mailed_at', 'mail_error', 'collected_at', 'collection_batch', 'credits_invoice_id', 'booked_at', 'booking_number', 'booking_error'];
}
