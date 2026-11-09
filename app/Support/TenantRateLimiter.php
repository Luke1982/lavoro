<?php

namespace App\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Factory;

/**
 * The rate limiter, counting in the active customer's cache.
 *
 * Laravel makes the limiter once, at boot, before a request has named its
 * customer, so it held on to the central cache for good. The throttle keys a
 * signed-in user by id, and ids repeat in every customer's database: user 1 of
 * one customer used up the attempts of user 1 of every other. Each switch of
 * customer now hands the limiter that customer's cache. Outside a customer --
 * the login, the panel -- it counts in the central cache, as before.
 */
class TenantRateLimiter extends RateLimiter implements ForgetsTenantState
{
    public function __construct(private readonly Factory $caches, private readonly ?string $store)
    {
        parent::__construct($caches->store($store));
    }

    public function forgetTenantState(): void
    {
        $this->cache = $this->caches->store($this->store);
    }
}
