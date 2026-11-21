<?php

namespace App\Models;

use App\Domain\Integrations\Area;
use App\Models\Traits\HasActivities;
use App\Models\Traits\HasIntegrations;
use App\Models\Traits\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An account in the bookkeeping that revenue is booked on, such as "8000
 * Omzet". A werkbon line goes onto the account of its material; a product
 * carries one for when products themselves are invoiced.
 *
 * Made here, or fetched from a linked system and kept in step with it like
 * customers are (Area::LedgerAccounts). Without a connection that exchanges
 * them an account here names nothing a bookkeeping system knows, so until
 * then the screens do not show them.
 */
class LedgerAccount extends Model
{
    use HasActivities;
    use HasIntegrations;
    use RecordsHistory;

    protected $fillable = ['code', 'name'];

    protected array $activity_labels = [
        'code' => 'Nummer',
        'name' => 'Omschrijving',
    ];

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** How a person reads it: "8000 Omzet". */
    public function getLabelAttribute(): string
    {
        return trim($this->code . ' ' . $this->name);
    }

    /** Whether an active connection fetches or sends them, which is when a material's account means anything. */
    public static function inUse(): bool
    {
        return IntegrationConnection::active()->get()->contains(
            fn (IntegrationConnection $connection) => $connection->exchanges(Area::LedgerAccounts),
        );
    }

    /**
     * The choices on a material or product, by number. Null while not in use,
     * which is what hides the field.
     *
     * @return array<int, array{id: int, name: string}>|null
     */
    public static function choices(): ?array
    {
        if (!self::inUse()) {
            return null;
        }

        return self::orderBy('code')->orderBy('name')->get()
            ->map(fn (self $account) => ['id' => $account->id, 'name' => $account->label])
            ->all();
    }
}
