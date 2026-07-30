<?php

namespace App\Domain\Tools\Read;

use App\Domain\Tools\Read\Concerns\OffersAChoice;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Customer;
use App\Models\User;

class FindCustomerTool implements Tool
{
    use OffersAChoice;

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
                'city' => [
                    'type' => 'string',
                    'description' => 'Beperk tot klanten in deze plaats. Gebruik dit als er een plaats '
                        . 'genoemd wordt, in plaats van alles op te halen en zelf te filteren: dan komt '
                        . 'de gebruiker met een handvol treffers ook echt een keuze te zien.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Deel van de naam, plaats, e-mailadres of telefoonnummer.',
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

    /** Eén zoekterm uit de vraag halen. Nauwelijks iets af te wegen. */
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
        $query = $call->stringArgument('query');

        if ($query === null || mb_strlen($query) < 2) {
            return ToolResult::failed('Geef minimaal twee tekens om op te zoeken.');
        }

        $limit = (int) config('assistant.max_results', 25);
        $like = '%' . $query . '%';

        $customers = Customer::query()
            ->where(fn ($q) => $q
                ->where('name', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('mobile', 'like', $like))
            ->when(
                filled($call->stringArgument('city')),
                fn ($q) => $q->where('city', 'like', '%' . $call->stringArgument('city') . '%')
            )
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'address', 'postal_code', 'city', 'email', 'phone']);

        if ($customers->isEmpty()) {
            return ToolResult::ok(
                ['customers' => [], 'note' => 'Geen klanten gevonden voor "' . $query . '".'],
                'Geen klanten gevonden voor "' . $query . '".',
            );
        }

        $content = ['customers' => $customers->toArray()];

        /**
         * A few matches and no way to tell which was meant is a choice, and it is
         * offered from here rather than left to the model to think of. Asked the
         * same thing twice it offered buttons once and a bulleted list the next.
         */
        $choice = $this->choiceOf(
            $customers,
            'Welke klant bedoel je?',
            'klant',
            'customers',
            fn ($customer) => trim($customer->name . ' — ' . $customer->address . ', ' . $customer->city, ' —,'),
        );

        if ($choice !== null) {
            $content['choice'] = $choice;
            $content['note'] = 'De gebruiker krijgt hier knoppen voor te zien. Som ze niet nog eens op; '
                . 'zeg kort wat je vond en laat hem kiezen.';
        }

        return ToolResult::ok(
            $content,
            $customers->count() . ' klant(en) gevonden voor "' . $query . '".',
        );
    }
}
