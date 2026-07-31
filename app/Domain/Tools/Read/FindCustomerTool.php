<?php

namespace App\Domain\Tools\Read;

use App\Domain\Tools\Read\Concerns\OffersAChoice;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Customer;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Collection;

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
                'id' => [
                    'type' => 'integer',
                    'description' => 'Het klantnummer, als je dat al hebt — bijvoorbeeld omdat de gebruiker '
                        . 'een klant heeft aangewezen. Dan hoef je niet op naam te zoeken.',
                ],
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
            /** Either will do, and the tool says so itself when neither is given. */
            'required' => [],
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
        /**
         * A number is the surest way in, and by this point in a conversation there
         * usually is one — somebody picked a customer and it came back as #1037.
         * Answered first, because searching for "1037" as text finds nothing and
         * reads as though the customer does not exist.
         */
        if ($id = $call->integerArgument('id')) {
            $found = Customer::query()
                ->whereKey($id)
                ->with('locations:id,customer_id,title,location_code,address,postal_code,city')
                ->get(['id', 'name', 'address', 'postal_code', 'city', 'email', 'phone']);

            return $found->isEmpty()
                ? ToolResult::notFound('Klant #' . $id)
                : $this->answerFor($found, 'klant #' . $id);
        }

        $query = $call->stringArgument('query');
        $city = $call->stringArgument('city');
        $by_place_alone = blank($query) && filled($city) && mb_strlen($city) >= 2;

        /**
         * A place on its own is a question, not half of one. "Welke klanten zijn er
         * in Meteren" was refused three times in a row, each time asking for a name
         * the person had already said they did not have — which is the whole reason
         * they were asking.
         */
        if (!$by_place_alone && ($query === null || mb_strlen($query) < 2)) {
            return ToolResult::failed(
                'Geef een klantnummer, minimaal twee tekens om op te zoeken, of een plaats in city.'
            );
        }

        $limit = (int) config('assistant.max_results', 25);

        $like = $call->likeArgument('query');

        $matching = Customer::query()
            ->when(!$by_place_alone, fn ($q) => $q->where(fn ($inner) => $inner
                ->where('name', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('mobile', 'like', $like)))
            ->when(
                filled($city),
                fn ($q) => $q->where('city', 'like', $call->likeArgument('city'))
            );

        $customers = (clone $matching)
            ->orderBy('name')
            ->limit($limit)
            /**
             * The locations come too. A customer can have several sites, and a job
             * planned against the wrong one sends somebody to a real address that
             * happens to be the wrong building.
             */
            ->with('locations:id,customer_id,title,location_code,address,postal_code,city')
            ->get(['id', 'name', 'address', 'postal_code', 'city', 'email', 'phone']);

        $described = $by_place_alone ? 'plaats "' . $city . '"' : '"' . $query . '"';

        if ($customers->isEmpty()) {
            return ToolResult::ok(
                ['customers' => [], 'note' => 'Geen klanten gevonden voor ' . $described . '.'],
                'Geen klanten gevonden voor ' . $described . '.',
            );
        }

        /**
         * How many there really are, not how many fitted. Told "25 gevonden" for a
         * village with eighty, somebody reasonably reads it as the whole list.
         */
        $total = $customers->count() < $limit ? $customers->count() : $matching->count();

        return $this->answerFor($customers, $described, filled($city), $total);
    }

    /**
     * One shape for the answer, whether the customers were found by number or by
     * name. Built here because the locations and the choice that hangs off them
     * belong to the answer rather than to the way it was asked for.
     *
     * @param  Collection<int, Customer>  $customers
     */
    private function answerFor(
        Collection $customers,
        string $described,
        bool $place_already_given = false,
        ?int $total = null,
    ): ToolResult {
        $content = ['customers' => $customers->map(fn (Customer $customer) => [
            'id' => $customer->id,
            'name' => $customer->name,
            'address' => $customer->address,
            'postal_code' => $customer->postal_code,
            'city' => $customer->city,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'locations' => $customer->locations->map(fn (Location $location) => [
                'id' => $location->id,
                'title' => $location->title,
                'code' => $location->location_code,
                'address' => $location->addressLine(),
            ])->all(),
        ])->all()];

        /**
         * One customer with more than one site is the next question, and it is
         * worth asking before anything gets planned: the address on the customer is
         * not necessarily where the work happens.
         */
        if ($customers->count() === 1 && $customers->first()->locations->count() > 1) {
            $sites = $this->choiceOf(
                $customers->first()->locations,
                'Op welke locatie van ' . $customers->first()->name . '?',
                'locatie',
                /** A location has its own page; hanging it off the customer's produced /customers/1911/7. */
                'locations',
                fn (Location $location) => trim(
                    ($location->title ?: $location->location_code ?: 'Locatie') . ' — ' . $location->addressLine(),
                    ' —'
                ),
            );

            if ($sites !== null) {
                $content['choice'] = $sites;
                $content['note'] = 'Deze klant heeft meerdere locaties en de gebruiker krijgt daar knoppen '
                    . 'voor te zien. Plan niets in en maak geen werkbon voordat duidelijk is welke locatie '
                    . 'het is; het adres van de klant is niet per se waar het werk gebeurt.';
            }
        }

        /**
         * Too many to choose between, and handing over the whole list is what lets
         * the narrowing happen out of sight: asked for a customer "iets met dijk"
         * it took twenty-five rows, picked out the three in Ede itself, and listed
         * them in prose where nothing could turn them into buttons.
         *
         * So a broad match comes back as a way to narrow instead. The next call
         * carries the place, returns a handful, and the choice offers itself.
         */
        $places = $this->choiceOfValues(
            $customers->pluck('city'),
            'In welke plaats?',
            'plaats',
        );

        if ($customers->count() > 8) {
            $per_place = $customers->groupBy('city')->map->count()->sortDesc();
            $found = $total ?? $customers->count();

            $content = [
                'customers' => [],
                'matches' => $found,
                'per_place' => $per_place->take(15)->all(),
            ];

            /**
             * Asking for a place when the place was the question is the loop this
             * fell into: "welke klanten zijn er in Meteren" came back as "in welke
             * plaats?" three times over. With the place already given, the only
             * thing left to narrow by is the name — so that is what it asks for.
             */
            $content['note'] = $place_already_given
                ? 'Te veel klanten om iemand uit te laten kiezen, dus de regels zijn niet meegestuurd '
                    . '— noem er geen enkele bij naam. De plaats is al bekend, dus vraag om een deel '
                    . 'van de klantnaam en zoek opnieuw met city én query. Zeg er wel bij hoeveel het '
                    . 'er in die plaats zijn.'
                : 'Te veel klanten om iemand uit te laten kiezen, dus de regels zijn niet '
                    . 'meegestuurd — filter zelf niet op wat je niet hebt. Noemde de gebruiker een '
                    . 'plaats, zoek dan opnieuw met city erbij. Deed hij dat niet, vraag dan in welke '
                    . 'plaats, en gebruik daarvoor ask_which_one met de plaatsen hierboven.';

            /** Buttons only when there are few enough places for them to help. */
            if ($places !== null && !$place_already_given) {
                $content['choice'] = $places;
                $content['note'] .= ' De gebruiker krijgt hier al knoppen per plaats te zien; '
                    . 'som ze dan niet nog eens op.';
            }

            return ToolResult::ok(
                $content,
                $place_already_given
                    ? $found . ' klanten daar; een deel van de naam erbij maakt het een keuze.'
                    : $found . ' klanten gevonden; eerst de plaats kiezen.',
            );
        }

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
            $customers->count() . ' klant(en) gevonden voor ' . $described . '.',
        );
    }
}
