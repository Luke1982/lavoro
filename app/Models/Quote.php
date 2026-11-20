<?php

namespace App\Models;

use App\Domain\Quotes\QuoteTotals;
use App\Enums\QuoteAcceptedVia;
use App\Enums\QuoteStatuses;
use App\Models\Traits\HasActivities;
use App\Models\Traits\HasOwner;
use App\Models\Traits\RecordsHistory;
use App\Support\QuoteNumbers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * An offerte: what was offered to a customer, line by line, at the prices of the
 * day it was made. Part of the paid module 'quotes'.
 */
class Quote extends Model
{
    use HasActivities;
    use HasOwner;
    use RecordsHistory;

    protected $fillable = [
        'title',
        'customer_id',
        'location_id',
        'contact_id',
        'project_id',
        'quote_date',
        'valid_until',
        'intro_text',
        'closing_text',
        'customer_picks_options',
    ];

    /**
     * A signature is a person's handwriting: the quote page shows it to who may
     * see the quote, and nothing else carries it.
     */
    protected $hidden = ['accepted_signature'];

    protected array $activity_labels = [
        'number' => 'Offertenummer',
        'title' => 'Titel',
        'customer_id' => 'Klant',
        'location_id' => 'Locatie',
        'contact_id' => 'Contactpersoon',
        'project_id' => 'Project',
        'quote_date' => 'Offertedatum',
        'valid_until' => 'Geldig tot',
        'intro_text' => 'Openingstekst',
        'closing_text' => 'Slottekst',
        'customer_picks_options' => 'Klant kiest zelf de opties',
    ];

    protected array $activity_relations = [
        'customer_id' => ['customer', 'name'],
        'contact_id' => ['contact', 'full_name'],
        'location_id' => ['location', 'title'],
        'project_id' => ['project', 'number'],
    ];

    /** Reported by QuoteStatusChanged and its kind, which stamp these along with the status. */
    protected array $activity_ignore = [
        'status',
        'sent_at',
        'decided_at',
        'accepted_name',
        'accepted_signature',
        'accepted_via',
        'service_order_id',
    ];

    protected function casts(): array
    {
        return [
            'quote_date' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'sent_at' => 'datetime',
            'accepted_via' => QuoteAcceptedVia::class,
            'decided_at' => 'datetime',
            'customer_picks_options' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Quote $quote) {
            if (blank($quote->number)) {
                $quote->number = QuoteNumbers::next(now()->year);
            }
        });

        static::deleting(fn (Quote $quote) => DB::table('activityables')
            ->where('activityable_type', Quote::class)
            ->where('activityable_id', $quote->id)
            ->delete());
    }

    public function statusEnum(): QuoteStatuses
    {
        return QuoteStatuses::from($this->status);
    }

    /**
     * Where a plain status button may take this quote now. None once a werkbon
     * was made from it: then it stays accepted.
     *
     * @return array<int, QuoteStatuses>
     */
    public function statusesByHand(): array
    {
        return $this->service_order_id === null ? $this->statusEnum()->nextByHand() : [];
    }

    public function label(): string
    {
        return $this->number . ' (' . $this->title . ')';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    /** The links the customer was sent. */
    public function accessTokens(): MorphMany
    {
        return $this->morphMany(AccessToken::class, 'tokenable')->latest();
    }

    /** The links the customer answered on, newest first. */
    public function answeredLinks(): MorphMany
    {
        return $this->accessTokens()->whereNotNull('used_at');
    }

    /**
     * Every link to this quote that could still be answered stops working,
     * except the one just sent: what they showed is no longer what is offered,
     * or there is a newer link to it.
     */
    public function withdrawLinks(?AccessToken $except = null): void
    {
        $this->accessTokens()->usable()
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->get()
            ->each->revoke();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('position')->orderBy('id');
    }

    /** A line at the bottom of the quote. */
    public function addLine(array $attributes): QuoteLine
    {
        return $this->lines()->create([
            ...$attributes,
            'position' => (int) $this->lines()->whereNull('parent_id')->max('position') + 1,
        ]);
    }

    public function totals(): QuoteTotals
    {
        return QuoteTotals::of($this->relationLoaded('lines') ? $this->lines : $this->lines()->get());
    }
}
