<?php

namespace App\Domain\Tools\Read;

use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Asset;
use App\Models\User;

class FindAssetTool implements Tool
{
    public static function name(): string
    {
        return 'find_asset';
    }

    public function description(): string
    {
        return 'Zoekt machines op serienummer, product of klant, met hun onderhoudsdatum. '
            . 'Gebruik dit bij vragen over een specifieke machine, of over welke machines bij een klant staan.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'serial_number' => [
                    'type' => 'string',
                    'description' => 'Heel of deel van het serienummer.',
                ],
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'Beperk tot machines van deze klant.',
                ],
                'due_within_days' => [
                    'type' => 'integer',
                    'description' => 'Alleen machines waarvan het onderhoud binnen zoveel dagen valt.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum aantal resultaten.',
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

    /** Serienummer of klant uit de vraag halen; de rest is filteren. */
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
        $limit = min($call->integerArgument('limit') ?? 10, (int) config('assistant.max_results', 25));

        $query = Asset::query()
            ->visibleTo($call->user)
            ->with(['product:id,brand_id,model', 'product.brand:id,name', 'customer:id,name']);

        if ($serial = $call->stringArgument('serial_number')) {
            $query->where('serial_number', 'like', '%' . $serial . '%');
        }

        if ($customer_id = $call->integerArgument('customer_id')) {
            $query->where('customer_id', $customer_id);
        }

        if ($days = $call->integerArgument('due_within_days')) {
            $query->whereBetween('next_service_date', [now(), now()->addDays($days)]);
        }

        $assets = $query->orderBy('next_service_date')->limit($limit)->get();

        $rows = $assets->map(fn (Asset $asset) => [
            'id' => $asset->id,
            'serial_number' => $asset->serial_number,
            'product' => $asset->product?->display_name,
            'customer' => $asset->customer?->name,
            'customer_id' => $asset->customer_id,
            'status' => $asset->status,
            'next_service_date' => $asset->next_service_date,
        ])->all();

        return ToolResult::ok(
            ['assets' => $rows],
            count($rows) . ' machine(s) gevonden.',
        );
    }
}
