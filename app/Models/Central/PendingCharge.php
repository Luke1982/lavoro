<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class PendingCharge extends Model
{
    protected $connection = 'central';

    protected $table = 'pending_charges';

    protected $fillable = ['tenant_id', 'description', 'kind', 'amount_cents', 'invoice_id'];
}
