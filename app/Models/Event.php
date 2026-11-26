<?php

namespace App\Models;

use App\Domain\Planning\Clock;
use App\Domain\Search\AddressTerm;
use App\Domain\Search\SearchTerm;
use App\Domain\Signals\Appointments\AppointmentCrewChanged;
use App\Domain\Signals\Appointments\AppointmentCustomerChanged;
use App\Domain\Signals\Signals;
use App\Domain\Signals\Users\NameListChange;
use App\Enums\EventStatusses;
use App\Models\Traits\HasActivities;
use App\Models\Traits\HasExecutingUsers;
use App\Models\Traits\HasOwner;
use App\Models\Traits\RecordsHistory;
use App\Models\Traits\RemarkableTrait;
use App\Services\EventLocationResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Event extends Model
{
    use HasActivities;
    use HasExecutingUsers;
    use HasFactory;
    use HasOwner;
    use RecordsHistory;
    use RemarkableTrait;
    use SoftDeletes;

    protected array $activity_labels = [
        'no_service_order' => 'Zonder werkbon',
        'name' => 'Naam',
        'description' => 'Omschrijving',
        'start' => 'Start',
        'end' => 'Eind',
        'status' => 'Status',
        'event_type_id' => 'Type',
        'location' => 'Locatie',
        'location_id' => 'Locatie',
        'is_preliminary' => 'Voorlopig',
    ];

    protected array $activity_relations = [
        'location_id' => ['linkedLocation', 'title'],
        'event_type_id' => ['eventType', 'name'],
    ];

    protected array $activity_ignore = [
        'origin',
    ];

    protected $fillable = [
        'name',
        'description',
        'event_type_id',
        'start',
        'end',
        'status',
        'location',
        'location_id',
        'origin',
        'is_preliminary',
        'no_service_order',
    ];

    protected $casts = [
        'start' => 'datetime',
        'end' => 'datetime',
        'is_preliminary' => 'boolean',
        'no_service_order' => 'boolean',
    ];

    /** Kept eager so EventLocationResolver never triggers an N+1 walking events. */
    protected $with = ['linkedLocation'];

    public static function statusses()
    {
        return EventStatusses::comboBoxArray();
    }

    /** A cancelled appointment did not happen and will not: it plans nothing. */
    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->where('status', '!=', EventStatusses::cancelled->value);
    }

    public function isCancelled(): bool
    {
        return $this->status === EventStatusses::cancelled->value;
    }

    /** Everybody's with the right to see them all, otherwise the ones this person executes or owns; nobody's without a person. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasPermission('event.see_all')) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->whereHas('executingUsers', fn ($sq) => $sq->where('users.id', $user->id))
                ->orWhereHas('owners', fn ($sq) => $sq->where('users.id', $user->id)->where('userables.type', 'owner'));
        });
    }

    /**
     * The last moment this person may see appointments up to, or null when there
     * is no limit. Without `event.see_beyond_current_week` that is a week ahead,
     * and every way in to appointments holds to it: the planner, its search, the
     * spotlight, a werkbon and the dashboard.
     */
    public static function horizonFor(User $user): ?CarbonImmutable
    {
        return $user->can('seeBeyondCurrentWeek', self::class)
            ? null
            : CarbonImmutable::now()->startOfDay()->addDays(7)->endOfDay();
    }

    public function scopeWithinHorizonOf(Builder $query, User $user): Builder
    {
        $horizon = self::horizonFor($user);

        return $horizon ? $query->where('start', '<=', $horizon) : $query;
    }

    /**
     * Appointments the term fits anywhere in: the appointment itself, its
     * customer, its location, and the werkbon and project behind it. One list for
     * the planner's search bar and the spotlight, so a term finds the same
     * appointments in both. Every rung of EventLocationResolver is a place the
     * address may live, so an address search reaches all of them.
     */
    public function scopeMatchesSearch(Builder $query, string $term): Builder
    {
        $like = SearchTerm::like($term);
        $address = AddressTerm::like($term);
        $order_id = ServiceOrder::idFromNumber($term);

        $match_location = fn ($lq) => $lq
            ->where('locations.title', 'like', $like)
            ->orWhereRaw(AddressTerm::sql('locations.address', 'locations.postal_code', 'locations.city'), [$address]);

        $match_customer = fn ($cq) => $cq
            ->where('customers.name', 'like', $like)
            ->orWhereRaw(AddressTerm::sql('customers.address', 'customers.postal_code', 'customers.city'), [$address]);

        return $query->where(fn ($q) => $q
            ->where('name', 'like', $like)
            ->orWhere('description', 'like', $like)
            ->orWhereRaw(AddressTerm::sql('events.location'), [$address])
            ->orWhereHas('linkedLocation', $match_location)
            ->orWhereHas('customers', $match_customer)
            ->orWhereHas('serviceOrders', fn ($sq) => $sq->where(fn ($oq) => $oq
                ->where('external_purchaseorder_no', 'like', $like)
                ->orWhereRaw(AddressTerm::sql('service_orders.execution_location'), [$address])
                ->orWhereHas('linkedLocation', $match_location)
                ->orWhereHas('customer', $match_customer)
                ->orWhereHas('project', fn ($pq) => $pq
                    ->where('title', 'like', $like)
                    ->orWhere('number', 'like', $like)
                    ->orWhereRaw(AddressTerm::sql('projects.location'), [$address])
                    ->orWhereHas('linkedLocation', $match_location)
                    ->when(is_numeric($term), fn ($iq) => $iq->orWhere('id', $term)))
                ->when($order_id !== null, fn ($iq) => $iq->orWhere('service_orders.id', $order_id)))));
    }

    public function eventType()
    {
        return $this->belongsTo(EventType::class);
    }

    /**
     * The linked customer location. Deliberately not named `location()`: that
     * name is already taken by the free-text `location` column, which would
     * shadow the relation on attribute access.
     */
    public function linkedLocation()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * This appointment's own location: a linked location's address wins over the
     * free-text location, which is only a snapshot.
     */
    public function getResolvedLocationAttribute(): ?string
    {
        if ($this->linkedLocation) {
            return $this->linkedLocation->addressLine();
        }

        return $this->location ?: null;
    }

    /**
     * The full escalation (see EventLocationResolver) — the address actually
     * shown for this appointment. Append it explicitly on queries that eager-load
     * serviceOrders; it is not appended globally to avoid an N+1.
     */
    public function getDisplayLocationAttribute(): ?string
    {
        return app(EventLocationResolver::class)->resolve($this);
    }

    /**
     * Whether the address shown for this appointment is one of its own that the
     * escalation would not have produced — an address entered for this visit
     * only. Append it alongside display_location; same relations, same caveat.
     */
    public function getHasDeviatingLocationAttribute(): bool
    {
        return app(EventLocationResolver::class)->deviatesFromInherited($this);
    }

    /**
     * Where this appointment would happen without an address of its own — what
     * the planner dialog offers as the placeholder behind an empty field.
     */
    public function getInheritedLocationAttribute(): ?string
    {
        return app(EventLocationResolver::class)->inherited($this);
    }

    public function serviceOrders()
    {
        return $this->morphedByMany(ServiceOrder::class, 'eventable');
    }

    public function customers()
    {
        return $this->morphedByMany(Customer::class, 'eventable');
    }

    public function primaryCustomer(): ?Customer
    {
        return $this->serviceOrders->first()?->customer ?? $this->customers->first();
    }

    /**
     * Whose work it is: the customer of the werkbon, and for an appointment
     * without one the customer it hangs off directly.
     */
    public function customerName(): ?string
    {
        return $this->primaryCustomer()?->name;
    }

    /**
     * The appointment as somebody recognises it: "afspraak #12 (Onderhoud) van
     * 01-12-2026 08:00–10:00, werkbon WB-0005, Klant BV". Number, sort, both ends and
     * whose work it is, which is what tells two appointments on one day apart.
     */
    public function label(): string
    {
        $this->loadMissing(['eventType:id,name', 'serviceOrders.customer:id,name', 'customers:id,name']);
        $order = $this->serviceOrders->first();
        $customer = $this->customerName();

        return 'afspraak #' . $this->id
            . ($this->eventType ? ' (' . $this->eventType->name . ')' : '')
            . ' van ' . self::window($this->start, $this->end)
            . ($order ? ', werkbon ' . $order->number : '')
            . ($customer ? ', ' . $customer : '');
    }

    /**
     * Both ends of an appointment, on the clock it is read on: a start alone
     * hides the half most easily got wrong. The second date only when it ends
     * on another day.
     */
    public static function window(mixed $start, mixed $end): string
    {
        $from = Clock::toLocal($start);
        $until = Clock::toLocal($end);

        return $from->format('d-m-Y H:i') . '–'
            . ($from->isSameDay($until) ? $until->format('H:i') : $until->format('d-m-Y H:i'));
    }

    /** "Afspraak "Onderhoud" van 01-12-2026 08:00": a werkbon can have several, so the moment names it. */
    public function historyLabel(): ?string
    {
        $name = $this->name ? ' "' . $this->name . '"' : '';
        $moment = $this->start ? ' van ' . Clock::toLocal($this->start)->format('d-m-Y H:i') : '';

        return 'Afspraak' . $name . $moment;
    }

    /**
     * Hangs the appointment directly off exactly these customers -- the ones an
     * appointment without a werkbon is for -- and says who it came onto and who
     * it left, on the appointment and on both customers.
     *
     * A customer the appointment now reaches through the werkbon it was put on
     * has not lost it, and is not told it was "losgekoppeld".
     *
     * @param  array<int, int|string|null>  $customer_ids
     */
    public function syncCustomers(array $customer_ids, ?ServiceOrder $through = null): void
    {
        $names = fn () => $this->customers()->pluck('customers.name', 'customers.id')->all();
        $before = $names();

        $this->customers()->sync(array_values(array_filter($customer_ids)));

        $still_for = $through ? array_intersect_key($before, [$through->customer_id => true]) : [];
        $customers = new NameListChange($before, $names() + $still_for);

        if (!$customers->isEmpty()) {
            Signals::dispatch(new AppointmentCustomerChanged($this, $customers));
        }
    }

    /**
     * Called by HasExecutingUsers after every sync of the crew, with who was on
     * the appointment before and after.
     *
     * @param  array<int, array<string, mixed>>  $before
     * @param  array<int, array<string, mixed>>  $after
     */
    protected function executingUsersChanged(array $before, array $after): void
    {
        $signal = new AppointmentCrewChanged($this, $before, $after);

        if (!$signal->isEmpty()) {
            Signals::dispatch($signal);
        }
    }

    public function executions(): HasMany
    {
        return $this->hasMany(EventUserExecution::class);
    }

    public function executionFor(int $user_id): EventUserExecution
    {
        return $this->executions()->firstOrCreate(
            ['user_id' => $user_id],
            ['completion_status' => 'Gepland'],
        );
    }

    /**
     * When a technician is planned on this appointment: the appointment's own times,
     * unless they have diverging times. Those are wall-clock times on the
     * appointment's local day, so they are read in the given timezone.
     *
     * @param  User|null  $member  an executing user, loaded with its pivot
     * @return array{0: Carbon, 1: Carbon}
     */
    public function plannedTimesFor(?User $member, string $timezone): array
    {
        $pivot = $member?->pivot;

        if (!$pivot?->has_diverging_times || !$pivot->diverging_start || !$pivot->diverging_end) {
            return [$this->start, $this->end];
        }

        $local_date = $this->start->copy()->setTimezone($timezone)->toDateString();

        return [
            Carbon::parse($local_date . ' ' . $pivot->diverging_start, $timezone),
            Carbon::parse($local_date . ' ' . $pivot->diverging_end, $timezone),
        ];
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withPivot(['main', 'internal'])
            ->wherePivot('internal', false)
            ->withTimestamps();
    }
}
