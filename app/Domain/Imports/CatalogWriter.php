<?php

namespace App\Domain\Imports;

use App\Exceptions\Refusal;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes one planned row: makes the brand, type, category or unit it names when
 * that does not exist yet, then makes or changes the record and links it to the
 * supplier. A change goes through the model like any edit on screen, so it shows
 * up in the record's history with the old and the new value.
 */
class CatalogWriter
{
    /** @var array<string, class-string<Model>> attribute => lookup model */
    private array $lookup_models = [];

    /**
     * @param  array<string, array<string, mixed>>  $fields
     */
    public function __construct(private CatalogTarget $target, array $fields, private ?Supplier $supplier)
    {
        foreach ($fields as $definition) {
            if ($definition['type'] === 'lookup') {
                $this->lookup_models[$definition['attribute']] = $definition['lookup'];
            }
        }
    }

    public static function for(CatalogPlan $plan): self
    {
        return new self($plan->target(), $plan->fields(), $plan->supplier());
    }

    /** @param  array<string, mixed>  $planned */
    public function write(array $planned): void
    {
        $attributes = $this->withLookupsMade($planned['attributes']);

        if ($planned['action'] === 'create') {
            $record = $this->target->create($attributes);
        } else {
            $record = $this->target->kind()->modelClass()::find($planned['record_id'])
                ?? throw new Refusal('Het ' . $this->target->noun() . ' is intussen verwijderd.');

            if ($attributes !== []) {
                $record->update($attributes);
            }
        }

        if ($planned['link_supplier'] && $this->supplier) {
            $record->suppliers()->syncWithoutDetaching([
                $this->supplier->id => ['article_number' => $planned['supplier_article']],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withLookupsMade(array $attributes): array
    {
        foreach ($attributes as $attribute => $value) {
            if (is_array($value) && isset($this->lookup_models[$attribute])) {
                $attributes[$attribute] = $this->lookup_models[$attribute]::query()
                    ->firstOrCreate(['name' => $value['new']])
                    ->id;
            }
        }

        return $attributes;
    }
}
