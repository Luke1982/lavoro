<?php

namespace App\Models;

use App\Enums\QuoteLineKinds;
use App\Support\RichText;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of a quote. Its own history is told by the quote signals, which name
 * the quote as subject; the line has no trail of its own.
 *
 * cost_price is hidden: a quote travels to people who may sell but not see the
 * margin. QuoteController makes it visible for quote.see_margins.
 */
class QuoteLine extends Model
{
    public const MARGIN_ATTRIBUTES = ['cost_price'];

    protected $fillable = [
        'quote_id',
        'parent_id',
        'kind',
        'item_type',
        'item_id',
        'position',
        'description',
        'details',
        'quantity',
        'unit',
        'unit_price',
        'cost_price',
        'vat_percent',
        'discount_percent',
        'is_optional',
        'is_chosen',
    ];

    protected $hidden = self::MARGIN_ATTRIBUTES;

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_price' => 'float',
            'cost_price' => 'float',
            'vat_percent' => 'float',
            'discount_percent' => 'float',
            'is_optional' => 'boolean',
            'is_chosen' => 'boolean',
        ];
    }

    /**
     * Whether the line is part of what is offered and counted: every line that
     * is not optional, and an optional one the customer chose.
     */
    public function isIncluded(): bool
    {
        return !$this->is_optional || $this->is_chosen === true;
    }

    /**
     * The paragraph under a text line comes from the editor and is shown as HTML,
     * so it is cleaned on its way in. Under any other line it is plain text.
     */
    protected function details(): Attribute
    {
        return Attribute::make(set: fn (?string $value, array $attributes) => ($attributes['kind'] ?? $this->kind) === QuoteLineKinds::text->value
            ? RichText::clean($value)
            : $value);
    }

    public function kindEnum(): QuoteLineKinds
    {
        return QuoteLineKinds::from($this->kind);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(QuoteLine::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    public function item(): MorphTo
    {
        return $this->morphTo();
    }

    /** A line under this one, after the ones already there: a material that came along with this product. */
    public function addChild(array $attributes): QuoteLine
    {
        return $this->children()->create([
            ...$attributes,
            'quote_id' => $this->quote_id,
            'position' => (int) $this->children()->max('position') + 1,
        ]);
    }

    /** The line total excluding VAT, after its discount, in cents. Text lines are worth nothing. */
    public function netCents(): int
    {
        if (!$this->kindEnum()->isPriced()) {
            return 0;
        }

        $gross = (int) round($this->quantity * $this->unit_price * 100);

        return (int) round($gross * (100 - $this->discount_percent) / 100);
    }

    public function costCents(): int
    {
        return $this->kindEnum()->isPriced() && $this->cost_price !== null
            ? (int) round($this->quantity * $this->cost_price * 100)
            : 0;
    }
}
