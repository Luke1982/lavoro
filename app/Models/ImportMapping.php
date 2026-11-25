<?php

namespace App\Models;

use App\Enums\CatalogImportKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved set of column choices for a spreadsheet import, kept under a name so the
 * next price list from the same supplier is mapped with one pick. Columns are
 * remembered by their header text, not their position, so a list that gained a
 * column in between still maps.
 *
 * @property CatalogImportKind $kind
 * @property array $settings
 */
class ImportMapping extends Model
{
    protected $fillable = [
        'kind',
        'name',
        'supplier_id',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'kind' => CatalogImportKind::class,
            'settings' => 'array',
        ];
    }

    /**
     * The kinds this person may import, and so read the saved mappings of.
     *
     * @return array<int, CatalogImportKind>
     */
    public static function importableKinds(User $user): array
    {
        return array_values(array_filter(
            CatalogImportKind::cases(),
            fn (CatalogImportKind $kind) => $user->can('import', $kind->modelClass()),
        ));
    }

    /** The mappings this person may read, mirroring ImportMappingPolicy. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($query->qualifyColumn('kind'), array_map(fn (CatalogImportKind $kind) => $kind->value, self::importableKinds($user)));
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * The mapping as the import screen picks it. The supplier comes from the
     * column, which is emptied when the supplier is deleted; the copy in the
     * settings would still name it.
     */
    public function toOption(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'settings' => [...$this->settings, 'supplier_id' => $this->supplier_id],
        ];
    }
}
