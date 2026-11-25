<?php

namespace App\Domain\Tools\Read;

use App\Domain\Tools\Read\Concerns\OffersAChoice;
use App\Domain\Tools\Read\Concerns\ReportsTheWholeCount;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Material;
use App\Models\User;

/**
 * The stock articles: what can be booked on a werkbon, how much is left, and
 * what has dropped under its minimum.
 *
 * Distinct from find_products, which is the catalogue of models a machine can
 * be. "Twee meter koelleiding" is a material, and booking it needs its number
 * and its unit — a material counted per roll booked as "2" is two rolls.
 *
 * Asked what has to be reordered, it answers with the materials below their
 * minimum and how many bring them back to their maximum. Nothing else in the
 * application says that, so the sum is made here and said as a suggestion.
 */
class FindMaterialTool implements Tool
{
    use OffersAChoice;
    use ReportsTheWholeCount;

    public static function name(): string
    {
        return 'find_materials';
    }

    public function description(): string
    {
        return 'Zoekt materialen (voorraadartikelen, geen producten): nummer, eenheid, voorraad, min/max. '
            . 'Voor "is er nog X?", voor het boeken op een werkbon, en met reorder voor wat er bijbesteld moet worden.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Naam of code.'],
                'reorder' => ['type' => 'boolean', 'description' => 'Alleen wat onder de minimumvoorraad is.'],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('view', Material::class);
    }

    /** Een materiaalnaam uit een zin halen. */
    public static function difficulty(): int
    {
        return 2;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public static function availableTo(): array
    {
        return ToolProfile::all();
    }

    public function execute(ToolCall $call): ToolResult
    {
        $limit = (int) config('assistant.max_results', 25);
        $reorder = $call->booleanArgument('reorder');
        $like = $call->likeArgument('query');

        $query = Material::query()
            ->with('usageUnit:id,name')
            ->when($like !== null, fn ($q) => $q->where(fn ($inner) => $inner
                ->where('name', 'like', $like)
                ->orWhere('code', 'like', $like)
                ->orWhere('vendor_code', 'like', $like)
                ->orWhere('description', 'like', $like)))
            /** A service has no stock to run out of, and an inactive article is not bought again. */
            ->when($reorder, fn ($q) => $q
                ->where('is_active', true)
                ->where('is_service', false)
                ->where('min_stock', '>', 0)
                ->whereColumn('stock', '<', 'min_stock'));

        $matching = clone $query;
        $materials = $query->orderBy('name')->limit($limit)->get();

        if ($materials->isEmpty()) {
            return ToolResult::ok(
                ['materials' => [], 'note' => $reorder
                    ? 'Niets staat onder de minimumvoorraad (alleen materialen met een minimum tellen mee).'
                    : 'Geen materiaal gevonden. Staat het niet in de catalogus, dan kan het als vrije regel.'],
                $reorder ? 'Niets bij te bestellen.' : 'Geen materialen gevonden.',
            );
        }

        $content = ['materials' => $materials->map(fn (Material $material) => $this->rowFor($material))->all()];

        $choice = $reorder || $like === null ? null : $this->choiceOf(
            $materials,
            'Welk materiaal bedoel je?',
            'materiaal',
            'materials',
            fn (Material $material) => $material->name . ($material->code ? ' (' . $material->code . ')' : ''),
        );

        if ($choice !== null) {
            $content['choice'] = $choice;
            $content['note'] = 'De gebruiker krijgt hier knoppen voor; som ze niet nog eens op.';
        }

        return $this->answerWithCount($content, $materials->count(), $matching, $limit, 'materialen');
    }

    /** @return array<string, mixed> */
    private function rowFor(Material $material): array
    {
        $stock = (float) $material->stock;
        $minimum = (float) $material->min_stock;
        $maximum = (float) $material->max_stock;
        $below_minimum = !$material->is_service && $minimum > 0 && $stock < $minimum;

        return array_filter([
            'material_id' => $material->id,
            'name' => $material->name,
            'code' => $material->code,
            'unit' => $material->usageUnit?->name,
            'divisible' => $material->divisable,
            'service' => $material->is_service ?: null,
            'inactive' => $material->is_active ? null : true,
            'stock' => $material->is_service ? null : $stock,
            'min_stock' => $minimum ?: null,
            'max_stock' => $maximum ?: null,
            'to_order' => $below_minimum ? max($maximum, $minimum) - $stock : null,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
