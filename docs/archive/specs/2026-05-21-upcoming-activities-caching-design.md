# Upcoming Activities — Accuracy-First Caching

**Date:** 2026-05-21
**Scope:** `GET /upcomingactivities` (`ActivityListController@getUpcomingActivities`)
**Goal:** Cache the response payload to mitigate slow loads when many records are in scope, **without ever serving stale data that contradicts the current database state**.

## Why this view is slow

Even when accuracy is the primary constraint, it helps to know where the cost is so we know what we're buying. The endpoint does:

1. One `Asset::upcomingAndUnplanned($days)` query (with a `whereDoesntHave` covering ServiceJob → ServiceOrder → Event).
2. One `Asset::expired()` query.
3. `getAssetsForListView` adds `with('customer')` and then `->unique()` on customer in PHP.
4. **`prepareAssetData` loops over each "main asset" customer and re-queries that customer's `upcomingAssets()`/`expiredAssets()` relation, eager-loading `product.brand`, `openTickets`, `pendingTickets`, `product.productType`, `pendingServiceJobs.serviceOrder.pastOpenEvents`, `pendingServiceJobs.serviceOrder.comingEvents`.** This is the dominant cost — it's an N+1 over customers with deep eager loads per iteration.
5. Returns `EventType::all()` and `User::all(['id','name'])` alongside.

A full-payload cache trades all of that for a single cache `get`. That's the win we're after.

## Data the payload depends on

| Model | Fields / relations consulted | Mutation surface |
|---|---|---|
| **Asset** | `next_service_date`, `status`, `customer_id`, `serial_number`, `product_id` | `AssetController` store/update/destroy; `ServiceJob` completion side-effect on `next_service_date` |
| **Ticket** | `status` (Open / In behandeling), `asset_id`, `service_order_id` | `TicketController` store/update/destroy; `ServiceOrderController` attachTicket/detachTicket |
| **ServiceJob** | `completed_on`, link to Asset + ServiceOrder | `ServiceJobController` store/update/bulkComplete/clearCompletedOn/destroy |
| **ServiceOrder** | `status` (closed/open) | `ServiceOrderController` store/update/destroy |
| **Event** | `status`, `start` | `EventApiController` store/update/destroy |
| **Customer** | `name`, `address`, `postal_code`, `city`, `lat`, `lon`, `id` | `CustomerController` store/update/updateCoords; daily SnelStart sync |
| **Product** | `model`, `brand_id` | `ProductController` store/update/destroy |
| **Brand** | `name` | `BrandController` store/update/destroy |

**Time-based decay (no mutation needed):**
- `upcomingAndUnplanned` uses `now()`..`now()+days` — assets roll into the window as the date crosses their `next_service_date`.
- `expired` uses `next_service_date < now()` — upcoming → expired purely by clock.
- Inside `upcomingAndUnplanned`, the "future event blocks this asset" check uses `start > now()` — past-due events stop blocking.

The first two roll over at **midnight** (date-only column). The third can roll over at **any minute**.

## Approach

**Global version counter + cache-key versioning.** All mutations route through a single chokepoint: `UpcomingActivitiesCacheVersion::bump()`. The cache key is built from the version, today's date, and the request params. There is exactly one way to invalidate, which makes the system auditable and hard to break.

### Components

#### 1. `UpcomingActivitiesCache` service (`app/Services/UpcomingActivitiesCache.php`)

```php
class UpcomingActivitiesCache
{
    private const VERSION_KEY = 'upcoming_activities:version';
    private const TTL_SECONDS = 300; // 5 min safety net

    public function key(int $days, ?string $search): string
    {
        $version = Cache::get(self::VERSION_KEY, 1);
        $date    = now()->toDateString(); // midnight rollover
        return "upcoming_activities:v{$version}:d{$date}:days{$days}:" . md5((string) $search);
    }

    public function remember(int $days, ?string $search, Closure $compute): array
    {
        // Bypass cache when a search is active (low hit rate, unbounded keyspace,
        // search result sets are small enough that uncached cost is acceptable).
        if ($search !== null && $search !== '') {
            return $compute();
        }
        return Cache::remember($this->key($days, $search), self::TTL_SECONDS, $compute);
    }

    public function bump(): void
    {
        // Cache::increment returns false on a non-existent key with the database driver.
        // Seed-then-increment makes this driver-agnostic.
        if (Cache::increment(self::VERSION_KEY) === false) {
            Cache::forever(self::VERSION_KEY, 1);
            Cache::increment(self::VERSION_KEY);
        }
    }
}
```

Notes:
- The `version` key never expires; it's an integer counter.
- Stale entries under old versions die naturally via TTL — no need to enumerate keys.
- TTL of 5 min is a defense-in-depth fallback. Under normal operation, `bump()` makes the cache appear cleared instantly.

#### 2. Observers — one per model, all calling `bump()`

Create `app/Observers/UpcomingActivitiesCacheObserver.php` as a single observer class registered against all 8 models in `AppServiceProvider::boot()`:

```php
Asset::observe(UpcomingActivitiesCacheObserver::class);
Ticket::observe(UpcomingActivitiesCacheObserver::class);
ServiceJob::observe(UpcomingActivitiesCacheObserver::class);
ServiceOrder::observe(UpcomingActivitiesCacheObserver::class);
Event::observe(UpcomingActivitiesCacheObserver::class);
Customer::observe(UpcomingActivitiesCacheObserver::class);
Product::observe(UpcomingActivitiesCacheObserver::class);
Brand::observe(UpcomingActivitiesCacheObserver::class);
```

The observer hooks `saved`, `deleted` (and `restored` if soft deletes are in play — none are at present, but the hook costs nothing to add). On any of these, call `app(UpcomingActivitiesCache::class)->bump()`.

**One observer, one method, one effect.** No per-model logic — any change to any of these models bumps the global version. This trades cache-hit rate for invalidation correctness, which is exactly the trade the user asked for.

#### 3. Controller wire-up

`ActivityListController@getUpcomingActivities`:

```php
$payload = $cache->remember($days, $search, function () use ($days, $search) {
    $upcoming = $this->getAssetsForListView(
        $this->applySearchFilter($this->getUpcomingAssetsQuery($days), $search)
    );
    $expired = $this->getAssetsForListView(
        $this->applySearchFilter($this->getExpiredAssetsQuery(), $search)
    );
    $this->prepareAssetData($upcoming, $days, 'upcoming');
    $this->prepareAssetData($expired,  $days, 'expired');

    return [
        'upcomingAssets' => $upcoming,
        'expiredAssets'  => $expired,
    ];
});

return inertia('ActivityList/UpcomingActivities', [
    ...$payload,
    'eventTypes' => EventType::all(),
    'allUsers'   => User::all(['id', 'name']),
]);
```

`eventTypes` and `allUsers` stay outside the cache — both are small, fast queries, and including them would force cache invalidation on every user creation.

The cached payload must be serialised in a stable form (Eloquent collections via Laravel's default array/JSON serialisation are fine; the cached value will hydrate back as plain arrays — Inertia handles that, no further work needed).

## Accuracy guarantees, explicitly

### What this design guarantees

1. **Any save/delete on Asset, Ticket, ServiceJob, ServiceOrder, Event, Customer, Product, or Brand** flips the cache to a new version on the next request. Worst-case staleness: the duration of one in-flight request that read the old version before the bump landed (sub-second).
2. **Midnight rollover** invalidates automatically — the date component in the key changes at 00:00 local time.
3. **Bulk SnelStart sync** uses `updateOrCreate`, which fires model events ⇒ Observer runs ⇒ bump happens (once per affected row, which is fine — bumps are cheap and idempotent for the request-side effect).
4. **Raw SQL bulk updates** that bypass model events (none currently exist in the codebase, but defensive against future additions) are caught by the 5-min TTL.
5. **`search=...` requests** bypass cache entirely — always fresh, no invalidation concern.

### Where staleness can leak — and why it's acceptable

There is exactly one edge case where the cache can be wrong by more than a few seconds:

- An `Event.start` time crosses `now()` (e.g., a scheduled event's start moment passes), the event was NOT marked completed, and no mutation occurs to bump the version. The asset blocked by that event should now re-appear in `upcomingAndUnplanned` (because the inner check uses `start > now()`). The cache will show the old "hidden" state until either a mutation bumps the version, or the 5-min TTL expires.

This is acceptable because:
- The view's purpose is "machines that need planning". An event whose start time has just passed but isn't marked completed is almost certainly currently in progress (the technician is at the customer). It shouldn't urgently re-appear on the planning list.
- 5-min worst-case latency on this niche case is much smaller than the user's current pain (slow loads now).

### What is explicitly NOT cached

- `eventTypes` and `allUsers` — outside the cache wrapper.
- Any request with a non-empty `search` parameter.
- The `/upcomingactivities/map` endpoint — separate concern; map view is much cheaper and not covered by this spec.

## File-by-file change list

1. **NEW** `app/Services/UpcomingActivitiesCache.php` — the service shown above.
2. **NEW** `app/Observers/UpcomingActivitiesCacheObserver.php` — single observer with `saved()` and `deleted()` methods that resolve the service and call `bump()`.
3. **EDIT** `app/Providers/AppServiceProvider.php` — register the observer against the 8 models in `boot()`.
4. **EDIT** `app/Http/Controllers/ActivityListController.php` — wrap the body of `getUpcomingActivities` with `$cache->remember(...)` as shown.
5. **NEW** `tests/Feature/UpcomingActivitiesCacheTest.php` — feature tests covering:
    - Two consecutive identical requests: second is a cache hit (assert via spy / second-call counter or that no DB queries fire for the cached portion).
    - Mutate each of the 8 models in turn; verify next request reflects the change.
    - Time-travel past midnight; verify the cache key changes.
    - Request with `search=...` always recomputes.
    - TTL expiry: travel >5 min, verify recompute even without a bump.

## Out of scope (deliberate)

- Optimising the `prepareAssetData` N+1 itself. That's an independent improvement; doing both at once muddles the perf measurement. The cache buys us instant response for the hot path; the N+1 only costs us on cache misses (first request after a mutation, or after midnight).
- Caching the `/upcomingactivities/map` endpoint.
- Per-customer / per-user fragment caching. Could be a follow-up if cache hit rate proves too low under heavy mutation traffic.
- Switching cache driver. Current `database` driver is fine for this workload (one key written, one key read per page load).

## Open questions

None blocking. If the cache hit rate turns out poor in production (lots of bumps, few reads between them), the natural next step is approach **B** (per-customer payload cache keyed by `customer_id + customer_version`). That work would build on this skeleton, not replace it.
