<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $connection = 'central';

    protected $table = 'coupons';

    protected $fillable = ['code', 'reseller_id', 'discount_percent', 'discount_months', 'redeemed_by_tenant_id', 'redeemed_at'];
}
