<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

abstract class ProductRequest extends FormRequest
{
    protected function duplicateMessage(): string
    {
        return 'Er bestaat al een product met exact dezelfde combinatie van merk, producttype, model'
            . ' en kenmerkwaarden binnen dezelfde verkoopperiode.';
    }

    protected function isExactDuplicate(
        mixed $brandId,
        mixed $typeId,
        mixed $modelName,
        mixed $startSell,
        mixed $endSell,
        array $attributeValueIds,
        ?int $excludeProductId = null
    ): bool {
        if (! $brandId || ! $typeId || ! $modelName) {
            return false;
        }

        $target = $this->normalizeAttributeValueIds($attributeValueIds);

        $candidates = Product::query()
            ->where('brand_id', $brandId)
            ->where('product_type_id', $typeId)
            ->where('model', $modelName)
            ->when($excludeProductId, fn($query) => $query->where('id', '!=', $excludeProductId))
            ->with('productAttributeValueables')
            ->get();

        foreach ($candidates as $candidate) {
            if (! $this->salePeriodsOverlap($startSell, $endSell, $candidate->start_sell, $candidate->end_sell)) {
                continue;
            }

            $existing = $this->normalizeAttributeValueIds(
                $candidate->productAttributeValueables->pluck('product_attribute_value_id')->all()
            );

            if ($existing->all() === $target->all()) {
                return true;
            }
        }

        return false;
    }

    private function normalizeAttributeValueIds(array $ids): Collection
    {
        return collect($ids)
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->sort()
            ->values();
    }

    private function salePeriodsOverlap(mixed $start_a, mixed $end_a, mixed $start_b, mixed $end_b): bool
    {
        $start_a = $start_a ?: null;
        $end_a = $end_a ?: null;
        $start_b = $start_b ?: null;
        $end_b = $end_b ?: null;

        if ($end_a !== null && $start_b !== null && (string) $end_a < (string) $start_b) {
            return false;
        }

        if ($end_b !== null && $start_a !== null && (string) $end_b < (string) $start_a) {
            return false;
        }

        return true;
    }

    protected function endSellOverlapClosure(
        mixed $brandId,
        mixed $typeId,
        mixed $modelName,
        mixed $startSell,
        ?int $excludeProductId = null
    ): \Closure {
        return function ($attribute, $value, $fail) use ($brandId, $typeId, $modelName, $startSell, $excludeProductId) {
            if (! $value || ! $startSell) {
                return;
            }

            $overlap = Product::query()
                ->where('brand_id', $brandId)
                ->where('product_type_id', $typeId)
                ->where('model', $modelName)
                ->when($excludeProductId, fn($q) => $q->where('id', '!=', $excludeProductId))
                ->where('start_sell', '<=', $value)
                ->where('end_sell', '>=', $startSell)
                ->exists();

            if ($overlap) {
                $fail('Er bestaat al een product met deze combinatie van merk, producttype en model dat valt binnen de opgegeven verkoopdatums.');
            }
        };
    }

    public function messages(): array
    {
        return [
            'end_sell.after_or_equal' => 'De einddatum van verkoop moet gelijk zijn aan of na de startdatum van verkoop.',
            'product_type_id.required' => 'Het producttype is verplicht.',
            'brand_id.required' => 'Het merk is verplicht.',
            'model.required' => 'Het model is verplicht.',
            'model.string' => 'Het model moet een geldige tekenreeks zijn.',
            'model.max' => 'Het model mag niet langer zijn dan 255 tekens.',
            'description.string' => 'De beschrijving moet een geldige tekenreeks zijn.',
            'start_sell.date' => 'De startdatum van verkoop moet een geldige datum zijn.',
            'end_sell.date' => 'De einddatum van verkoop moet een geldige datum zijn.',
            'retail_price.numeric' => 'De verkoopprijs moet een geldig getal zijn.',
            'retail_price.min' => 'De verkoopprijs mag niet negatief zijn.',
            'purchase_price.numeric' => 'De inkoopprijs moet een geldig getal zijn.',
            'purchase_price.min' => 'De inkoopprijs mag niet negatief zijn.',
            'typical_certificate_days.integer' => 'De certificeringstermijn moet een geheel getal zijn.',
            'typical_certificate_days.min' => 'De certificeringstermijn moet minimaal 1 dag zijn.',
        ];
    }
}
