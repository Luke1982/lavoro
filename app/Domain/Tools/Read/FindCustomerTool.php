<?php

namespace App\Domain\Tools\Read;

use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Customer;
use App\Models\User;

class FindCustomerTool implements Tool
{
    public static function name(): string
    {
        return 'find_customer';
    }

    public function description(): string
    {
        return 'Zoekt klanten op naam, plaats, e-mailadres of telefoonnummer. '
            . 'Gebruik dit zodra een vraag een klant bij naam noemt en je het klantnummer nog niet hebt, '
            . 'want alle andere tools werken op klant-id.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Deel van de naam, plaats, e-mailadres of telefoonnummer.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum aantal resultaten.',
                ],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('list', Customer::class);
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
        $query = $call->stringArgument('query');

        if ($query === null || mb_strlen($query) < 2) {
            return ToolResult::failed('Geef minimaal twee tekens om op te zoeken.');
        }

        $limit = min($call->integerArgument('limit') ?? 10, (int) config('assistant.max_results', 25));
        $like = '%' . $query . '%';

        $customers = Customer::query()
            ->where(fn ($q) => $q
                ->where('name', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('mobile', 'like', $like))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'address', 'postal_code', 'city', 'email', 'phone']);

        if ($customers->isEmpty()) {
            return ToolResult::ok(
                ['customers' => [], 'note' => 'Geen klanten gevonden voor "' . $query . '".'],
                'Geen klanten gevonden voor "' . $query . '".',
            );
        }

        return ToolResult::ok(
            ['customers' => $customers->toArray()],
            $customers->count() . ' klant(en) gevonden voor "' . $query . '".',
        );
    }
}
