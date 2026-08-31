<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class AiTopup extends Model
{
    protected $connection = 'central';

    protected $table = 'ai_topups';

    protected $fillable = ['tenant_id', 'paid_cents', 'granted_micros', 'note'];

    protected $casts = ['paid_cents' => 'integer', 'granted_micros' => 'integer'];
}
