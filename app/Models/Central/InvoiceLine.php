<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    protected $connection = 'central';

    protected $table = 'invoice_lines';

    protected $fillable = ['invoice_id', 'description', 'kind', 'amount_cents'];
}
