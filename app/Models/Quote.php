<?php

namespace App\Models;

use App\Casts\CleanHtml;
use App\Domain\Quotes\PaymentSchedule;
use App\Domain\Quotes\QuoteForCustomer;
use App\Domain\Quotes\QuoteLayout;
use App\Domain\Quotes\QuoteSettings;
use App\Domain\Quotes\QuoteTotals;
use App\Domain\Quotes\VatRates;
use App\Domain\Search\SearchTerm;
use App\Enums\QuoteAcceptedVia;
use App\Enums\QuotePricingMethods;
use App\Enums\QuoteStatuses;
use App\Models\Traits\HasActivities;
use App\Models\Traits\HasOwner;
use App\Models\Traits\RecordsHistory;
use App\Support\QuoteNumbers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
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

    /** The offertelijst opens on the quotes still waiting for an answer; 'all' is said out loud. */
    public const LIST_OPEN = 'open';

    public const LIST_EVERY_STATUS = 'all';

    /** How long a private customer may still change their mind, counted from the day they said yes. */
    public const COOLING_OFF_DAYS = 14;

    /** What a yes carries along, and what going back from "geaccepteerd" clears. */
    public const ACCEPTANCE = [
        'accepted_name',
        'accepted_signature',
        'accepted_via',
        'accepted_version_id',
        'accepted_document',
        'accepted_terms_name',
        'starts_within_cooling_off',
    ];

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
        'default_vat_percent',
        'is_consumer',
        'layout',
        'pricing_method',
        'start_period',
        'duration',
        'payment_terms',
        'payment_schedule',
    ];

    /**
     * A signature is a person's handwriting: the quote page shows it to who may
     * see the quote, and nothing else carries it. What was signed is read by the
     * PDF, never sent along with the quote.
     */
    protected $hidden = ['accepted_signature', 'accepted_document'];

    /** What a quote is before anyone chose otherwise; the columns default the same way. */
    protected $attributes = [
        'version' => 1,
        'default_vat_percent' => VatRates::DEFAULT,
        'pricing_method' => QuotePricingMethods::vaste_prijs->value,
        'is_consumer' => false,
    ];

    protected array $activity_labels = [
        'number' => 'Offertenummer',
        'title' => 'Titel',
        'customer_id' => 'Klant',
        'location_id' => 'Locatie',
        'contact_id' => 'Contactpersoon',
        'project_id' => 'Project',
        'quote_date' => 'Offertedatum',
        'valid_until' => 'Geldig tot',
        'intro_text' => 'Aanhef',
        'closing_text' => 'Slot',
        'default_vat_percent' => 'Standaard btw-tarief',
        'is_consumer' => 'Particuliere klant',
        'layout' => 'Weergave',
        'pricing_method' => 'Prijsafspraak',
        'start_period' => 'Start',
        'duration' => 'Duur',
        'payment_terms' => 'Betaling',
        'payment_schedule' => 'Betaaltermijnen',
    ];

    /** Editor HTML: the timeline reads it as plain text, the stored value keeps the markup. */
    protected array $activity_html = ['intro_text', 'closing_text'];

    /** Stored as JSON, read on the timeline as a sentence. */
    protected array $activity_formats = [
        'layout' => [QuoteLayout::class, 'describe'],
        'payment_schedule' => [PaymentSchedule::class, 'describe'],
    ];

    protected array $activity_relations = [
        'customer_id' => ['customer', 'name'],
        'contact_id' => ['contact', 'full_name'],
        'location_id' => ['location', 'title'],
        'project_id' => ['project', 'number'],
    ];

    /**
     * Reported by QuoteStatusChanged and its kinds, which stamp these along with
     * the status, and by QuoteVersionStarted and QuoteExpiresSoon.
     */
    protected array $activity_ignore = [
        'status',
        'sent_at',
        'decided_at',
        'lost_reason_id',
        'lost_reason_note',
        ...self::ACCEPTANCE,
        'service_order_id',
        'version',
        'expiry_noticed_for',
        'origin_type',
        'origin_id',
    ];

    protected function casts(): array
    {
        return [
            'quote_date' => 'date:Y-m-d',
            'intro_text' => CleanHtml::class,
            'closing_text' => CleanHtml::class,
            'valid_until' => 'date:Y-m-d',
            'sent_at' => 'datetime',
            'accepted_via' => QuoteAcceptedVia::class,
            'decided_at' => 'datetime',
            'default_vat_percent' => 'float',
            'is_consumer' => 'boolean',
            'layout' => 'array',
            'pricing_method' => QuotePricingMethods::class,
            'payment_schedule' => 'array',
            'starts_within_cooling_off' => 'boolean',
            'accepted_document' => 'array',
            'expiry_noticed_for' => 'date:Y-m-d',
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

    /**
     * Its creation and removal are news on the customer's and the project's
     * timeline. A deleted concept would otherwise leave no trace anywhere: the
     * quote takes its own timeline with it.
     */
    public function historyTimelines(string $action): array
    {
        return in_array($action, ['created', 'deleted'], true)
            ? [$this->customer, $this->project]
            : [];
    }

    public function historyPermission(): ?string
    {
        return 'quote.read';
    }

    public function historyLabel(): ?string
    {
        return 'Offerte ' . $this->number;
    }

    /**
     * The offertelijst's search: a piece of the number, the title or the
     * customer's name. The list and the assistant both search through here,
     * so a quote found on the screen is found when asked for too.
     */
    public function scopeSearchedFor(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = SearchTerm::like($term);

        return $query->where(fn (Builder $inner) => $inner
            ->where('number', 'like', $like)
            ->orWhere('title', 'like', $like)
            ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $like)));
    }

    /**
     * The offertelijst's status filter: open for every quote still waiting
     * for an answer, all for every one, otherwise that one status.
     */
    public function scopeInListStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            self::LIST_OPEN => $query->whereIn('status', QuoteStatuses::openValues()),
            self::LIST_EVERY_STATUS => $query,
            default => $query->where('status', $status),
        };
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

    /** What the customer was shown, each send, newest first. */
    public function versions(): HasMany
    {
        return $this->hasMany(QuoteVersion::class)->orderByDesc('number')->orderByDesc('id');
    }

    /** The last send of the version being worked on, if it went out. */
    public function latestSendOfVersion(): ?QuoteVersion
    {
        return $this->versions()->where('number', $this->version)->first();
    }

    /** What the customer holds and can say yes to while the quote is out: the last send. */
    public function sendOnOffer(): ?QuoteVersion
    {
        return $this->statusEnum()->awaitsAnswer() ? $this->latestSendOfVersion() : null;
    }

    /**
     * What the customer signed, as the signed copy prints it; for a yes given
     * before that was kept, the version they said yes to. Null while nobody did.
     *
     * @return array<string, mixed>|null
     */
    public function signedDocument(): ?array
    {
        return $this->accepted_name === null ? null : ($this->accepted_document ?? $this->acceptedVersion?->document());
    }

    /**
     * The general terms a yes goes with: those of the send it answers, by
     * default the one on offer, or, for a quote accepted as it stands, the ones
     * it would go out with today.
     *
     * @return array{name: string, path: string}|null
     */
    public function termsOnOffer(?QuoteVersion $send = null): ?array
    {
        $send ??= $this->sendOnOffer();

        return $send ? $send->document()['terms'] : QuoteSettings::terms();
    }

    public function acceptedVersion(): BelongsTo
    {
        return $this->belongsTo(QuoteVersion::class, 'accepted_version_id');
    }

    /**
     * Freezes the quote as the customer sees it now, under the number of the
     * version being worked on: when it is sent, with the address and the link,
     * or when it is accepted on a screen without having been sent like this.
     * Whoever sends passes the snapshot the mail's PDF was made from, so the two
     * cannot differ.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public function recordVersion(
        ?string $recipient = null,
        ?AccessToken $link = null,
        ?array $snapshot = null,
    ): QuoteVersion {
        $this->unsetRelation('lines');

        return $this->versions()->create([
            'number' => $this->version,
            'snapshot' => $snapshot ?? QuoteForCustomer::of($this),
            'recipient' => $recipient,
            'access_token_id' => $link?->id,
            'created_by' => auth()->id(),
        ]);
    }

    public function lostReason(): BelongsTo
    {
        return $this->belongsTo(QuoteLostReason::class);
    }

    /** The werkbon, storing, quote or template this quote was made from. */
    public function origin(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The columns the customer sees: this quote's own choice, or else the
     * settings; prices with VAT for a private customer, without for a business.
     */
    public function layoutForCustomer(): array
    {
        return QuoteLayout::complete($this->layout, [...QuoteSettings::layout(), 'incl_vat' => (bool) $this->is_consumer]);
    }

    /** Until when a private customer who said yes may still change their mind; null when that does not apply. */
    public function coolingOffUntil(): ?Carbon
    {
        return $this->is_consumer && $this->statusEnum() === QuoteStatuses::geaccepteerd && $this->decided_at
            ? $this->decided_at->copy()->addDays(self::COOLING_OFF_DAYS)
            : null;
    }

    /** Brochures, drawings and the like that belong with the quote. */
    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'documentable')->withTimestamps();
    }

    /** Photos with the quote: of the situation, or of what is offered. */
    public function images(): MorphToMany
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withPivot(['main'])
            ->withTimestamps();
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
