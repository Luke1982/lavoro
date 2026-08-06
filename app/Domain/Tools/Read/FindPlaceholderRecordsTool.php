<?php

namespace App\Domain\Tools\Read;

use App\Domain\Tools\Read\Concerns\ReportsTheWholeCount;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Asset;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The records somebody put in to get on with the job.
 *
 * A monteur on a roof cannot stop to work out which exact model he is looking
 * at, so he books it against a product called "onbekend" and a serial that says
 * the same. It keeps the werkbon moving and leaves the catalogue with holes
 * that nobody ever goes back for — there is no screen that lists them, so they
 * are invisible until somebody needs the machine's history.
 *
 * This finds them, so a conversation can work through them with the photos.
 */
class FindPlaceholderRecordsTool implements Tool
{
    use ReportsTheWholeCount;

    /** What people actually type when they mean "I will sort this out later". */
    private const MARKERS = ['onbekend', 'unknown', 'onbekende', 'nnb', 'n.v.t.', 'tbd'];

    public static function name(): string
    {
        return 'find_placeholder_records';
    }

    public function description(): string
    {
        return 'Zoekt machines en producten die als tijdelijke plaatshouder zijn ingevoerd — '
            . 'een productnaam of serienummer met "onbekend" erin, of iets vergelijkbaars. Gebruik '
            . 'dit als iemand vraagt wat er nog aangevuld moet worden, of om na te lopen welke '
            . 'machines nog een echt type en serienummer missen.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'Beperk tot de machines van deze klant.',
                ],
                'service_order_id' => [
                    'type' => 'integer',
                    'description' => 'Beperk tot de machines die op deze werkbon staan.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('listRelevant', Asset::class);
    }

    /** Knowing what counts as a placeholder is judgement; finding them is not. */
    public static function difficulty(): int
    {
        return 4;
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

        $matching = Asset::query()
            ->visibleTo($call->user)
            ->with(['product:id,brand_id,model', 'product.brand:id,name', 'customer:id,name'])
            ->where(fn ($outer) => $outer
                ->where(fn ($q) => $this->markedIn($q, 'assets.serial_number'))
                ->orWhereHas('product', fn ($q) => $this->markedIn($q, 'products.model')))
            ->when(
                $call->integerArgument('customer_id') !== null,
                fn ($q) => $q->where('customer_id', $call->integerArgument('customer_id'))
            )
            ->when(
                $call->integerArgument('service_order_id') !== null,
                fn ($q) => $q->whereHas(
                    'serviceOrders',
                    fn ($o) => $o->where('service_orders.id', $call->integerArgument('service_order_id'))
                )
            );

        $assets = (clone $matching)->orderBy('id')->limit($limit)->get();

        $rows = $assets->map(fn (Asset $asset) => [
            'asset_id' => $asset->id,
            'serial_number' => $asset->serial_number,
            'serial_is_placeholder' => $this->looksLikePlaceholder($asset->serial_number),
            'product_id' => $asset->product_id,
            'product' => $asset->product?->display_name,
            'product_is_placeholder' => $this->looksLikePlaceholder($asset->product?->model),
            'customer_id' => $asset->customer_id,
            'customer' => $asset->customer?->name,
            'link' => '/assets/' . $asset->id,
        ])->all();

        if ($rows === []) {
            return ToolResult::ok(
                ['assets' => [], 'note' => 'Geen tijdelijke machines gevonden binnen wat je mag zien.'],
                'Geen tijdelijke machines gevonden.',
            );
        }

        $content = [
            'assets' => $rows,
            'note' => 'Deze machines missen nog een echt type of serienummer. Staan er foto\'s bij de '
                . 'werkbon of de machine, bekijk die dan met view_images en lees het typeplaatje af. '
                . 'Vul niets in wat je niet van een plaatje of uit documentatie hebt.',
        ];

        return $this->answerWithCount($content, count($rows), $matching, $limit, 'tijdelijke machines');
    }

    /** @param \Illuminate\Contracts\Database\Query\Builder|Builder $query */
    private function markedIn($query, string $column): void
    {
        foreach (self::MARKERS as $index => $marker) {
            $query->{$index === 0 ? 'whereRaw' : 'orWhereRaw'}(
                'LOWER(' . $column . ') LIKE ?',
                ['%' . $marker . '%']
            );
        }
    }

    private function looksLikePlaceholder(?string $value): bool
    {
        if (blank($value)) {
            return true;
        }

        foreach (self::MARKERS as $marker) {
            if (str_contains(mb_strtolower($value), $marker)) {
                return true;
            }
        }

        return false;
    }
}
