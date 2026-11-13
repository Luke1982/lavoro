<?php

namespace App\Domain\Tools\Write;

use App\Actions\Tasks\CreateTaskInstanceAction;
use App\Actions\Tasks\RegisterTaskAssetsAction;
use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Asset;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTask;
use App\Models\ServiceOrderTaskInstance;
use App\Models\User;
use App\Services\TaskInstanceBundleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Puts a task on a werkbon: what is to be done, with which product, how many.
 *
 * This is the part somebody actually works from. A werkbon whose description
 * says "installatie airco" and carries no tasks tells a mechanic to install an
 * airco and nothing about which one or how many, which is the difference between
 * a job sheet and a note.
 */
class AddServiceOrderTaskTool implements Confirmable, Tool
{
    public static function name(): string
    {
        return 'add_service_order_task';
    }

    public function description(): string
    {
        return 'Zet een taak op een werkbon: wat er gedaan moet worden, met welk product en hoeveel. '
            . 'Gebruik dit voor de eigenlijke inhoud van de klus — "tien binnenunits plaatsen" — '
            . 'en zoek het product eerst op zodat je het echte productnummer meegeeft. '
            . 'Roep de tool aan zodra je dat hebt: er wordt nog niets vastgelegd, je krijgt terug dat '
            . 'er bevestiging nodig is en het systeem legt de knop aan de gebruiker voor.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'service_order_id' => [
                    'type' => 'integer',
                    'description' => 'De werkbon waar de taak op komt.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Wat er gedaan moet worden.',
                ],
                'product_id' => [
                    'type' => 'integer',
                    'description' => 'Het product dat geplaatst of gebruikt wordt. Zoek het eerst op.',
                ],
                'quantity' => [
                    'type' => 'integer',
                    'description' => 'Hoeveel er van dat product gaan. Standaard 1.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Korte titel voor de taak.',
                ],
                'service_order_task_id' => [
                    'type' => 'integer',
                    'description' => 'Een bestaande standaardtaak, als daar een van past.',
                ],
                'register_machines' => [
                    'type' => 'boolean',
                    'description' => 'Registreer meteen de machines die deze taken opleveren, bij de klant en op de '
                        . 'taak, met een tijdelijk serienummer dat de monteur bij het plaatsen invult. Voor '
                        . 'apparatuur die verkocht is maar nog niet hangt, zoals bij een getekende offerte.',
                ],
                'tasks' => [
                    'type' => 'array',
                    'description' => 'Meerdere taken in één keer, bijvoorbeeld één per product van een offerte. '
                        . 'Elke taak heeft dezelfde velden als hierboven; laat die velden op het hoogste niveau '
                        . 'dan weg.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'description' => ['type' => 'string'],
                            'product_id' => ['type' => 'integer'],
                            'quantity' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                            'service_order_task_id' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
            'required' => ['service_order_id'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        $registers_machines = filter_var($arguments['register_machines'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $user->can('addTask', ServiceOrder::class)
            && (!$registers_machines || $user->can('create', Asset::class));
    }

    /** Uit een omschrijving het product, het aantal en de handeling halen. */
    public static function difficulty(): int
    {
        return 5;
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public static function availableTo(): array
    {
        return [ToolProfile::planner, ToolProfile::administrator];
    }

    /**
     * Names the customer, not only the number.
     *
     * A task went onto werkbon #296 while the conversation above the button was
     * about #316 — a stale number the model reached for, and the button said only
     * "Taak op werkbon #296". Nothing about that reads as wrong at the moment of
     * clicking. With the customer on it, a task for MajorLabel landing on a werkbon
     * for somebody else is obvious without anybody checking a number.
     */
    public function previewOf(ToolCall $call): string
    {
        $order = ServiceOrder::with('customer:id,name')->find($call->integerArgument('service_order_id'));
        $specs = $this->specsOf($call);
        $products = Product::whereIn('id', array_filter(array_column($specs, 'product_id')))->get()->keyBy('id');

        $lines = array_map(function (array $spec) use ($products) {
            $product = $products[$spec['product_id']] ?? null;

            return ($spec['title'] ?: $spec['description'] ?: 'taak')
                . ($product ? ' — ' . $spec['quantity'] . 'x ' . $product->display_name : '');
        }, $specs);

        return (count($specs) > 1 ? count($specs) . ' taken op werkbon #' : 'Taak op werkbon #')
            . $call->integerArgument('service_order_id')
            . ($order?->customer ? ' (' . $order->customer->name . ')' : '')
            . ': ' . implode('; ', $lines)
            . ($this->registersMachines($call) ? ', met de machines erbij (tijdelijk serienummer)' : '');
    }

    public function execute(ToolCall $call): ToolResult
    {
        $order_id = $call->integerArgument('service_order_id');
        $order = $order_id === null
            ? null
            : ServiceOrder::visibleTo($call->user)->with('customer:id,name')->whereKey($order_id)->first();

        if ($order === null) {
            return ToolResult::notFound('Werkbon #' . ($order_id ?? '?'));
        }

        $specs = $this->specsOf($call);

        if ($specs === []) {
            return ToolResult::failed('Geef minstens één taak.');
        }

        $products = Product::visibleTo($call->user)
            ->whereIn('id', array_filter(array_column($specs, 'product_id')))
            ->get()
            ->keyBy('id');

        foreach ($specs as $spec) {
            $problem = $this->problemWith($spec, $products);

            if ($problem !== null) {
                return $problem;
            }
        }

        $machines = collect();
        $sets = collect();

        /** All of them or none: half an offerte on a werkbon reads as the whole one. */
        $instances = DB::transaction(function () use ($specs, $order, $call, &$machines, &$sets) {
            $create = app(CreateTaskInstanceAction::class);
            $instances = array_map(
                fn (array $spec) => $create->execute(['service_order_id' => $order->id] + $spec),
                $specs,
            );

            if ($this->registersMachines($call)) {
                $register = app(RegisterTaskAssetsAction::class);

                foreach ($instances as $instance) {
                    $rows = $register->placeholderRowsFor($instance);

                    if ($rows !== []) {
                        $machines = $machines->merge($register->execute($instance, $rows, in_service: false));
                    }

                    /** A bundle is delivered as the set with its parts underneath; the set is the machine. */
                    $sets = $sets->merge(app(TaskInstanceBundleService::class)->containers($instance->load('assets')));
                }
            }

            return $instances;
        });

        $tasks = array_map(fn (ServiceOrderTaskInstance $instance) => [
            'task_instance_id' => $instance->id,
            'description' => $instance->description,
            'product' => $products->get($instance->product_id)?->display_name,
            'quantity' => $instance->quantity,
        ], $instances);

        /** What stands at the customer: the sets, and the machines that are not part of one. */
        $delivered = $sets->merge($machines->whereNull('parent_asset_id'))->values();

        return ToolResult::ok(
            [
                ...(count($tasks) === 1 ? $tasks[0] : ['tasks' => $tasks]),
                'service_order_id' => $order->id,
                'customer' => $order->customer?->name,
                'asset_id' => $delivered->count() === 1 ? $delivered->first()->id : null,
                'machines' => $delivered->map(fn (Asset $machine) => [
                    'asset_id' => $machine->id,
                    'serial_number' => $machine->serial_number,
                ])->all(),
                'link' => '/serviceorders/' . $order->id,
            ],
            /**
             * Names the customer as well as the number. A task landed on werkbon
             * #296 while the conversation was about #316, and "toegevoegd aan
             * werkbon #296" reads as success either way.
             */
            (count($tasks) > 1 ? count($tasks) . ' taken' : 'Taak') . ' toegevoegd aan werkbon #' . $order->id
                . ($order->customer ? ' van ' . $order->customer->name : '')
                . ' (' . implode(', ', array_map($this->taskLine(...), $tasks)) . ')'
                . $this->machinesLine($machines, $sets) . '.',
        );
    }

    /** The same rules the form applies, to one task of the list. */
    private function problemWith(array $spec, Collection $products): ?ToolResult
    {
        $standard = $spec['service_order_task_id'];

        /**
         * A task is either one of the standard ones or it says in its own words
         * what to do. Neither, and it is a line on a job sheet that means nothing
         * to whoever reads it.
         */
        if (blank($spec['description']) && $standard === null) {
            return ToolResult::failed('Geef een omschrijving, of kies een bestaande standaardtaak.');
        }

        if ($standard !== null && !ServiceOrderTask::whereKey($standard)->exists()) {
            return ToolResult::notFound('Standaardtaak #' . $standard);
        }

        if ($spec['product_id'] !== null && !$products->has($spec['product_id'])) {
            return ToolResult::notFound('Product #' . $spec['product_id']);
        }

        if ($spec['quantity'] < 1 || $spec['quantity'] > 999) {
            return ToolResult::failed('Het aantal moet tussen 1 en 999 liggen.');
        }

        return null;
    }

    /** @param array{description: ?string, product: ?string, quantity: int} $task */
    private function taskLine(array $task): string
    {
        return $task['product'] ? $task['quantity'] . 'x ' . $task['product'] : ($task['description'] ?? 'taak');
    }

    /**
     * @param  Collection<int, Asset>  $machines
     * @param  Collection<int, Asset>  $sets
     */
    private function machinesLine(Collection $machines, Collection $sets): string
    {
        $line = $sets->isEmpty() ? '' : ', als ' . $sets->count() . ' set(s): '
            . $sets->map(fn (Asset $set) => '#' . $set->id)->implode(', ');

        if ($machines->isNotEmpty()) {
            $line .= ', met ' . $machines->count() . ' machine(s) met tijdelijk serienummer: ' . $machines
                ->map(fn (Asset $machine) => '#' . $machine->id . ' (' . ($machine->serial_number ?? 'geen') . ')')
                ->implode(', ');
        }

        return $line;
    }

    private function registersMachines(ToolCall $call): bool
    {
        return filter_var($call->argument('register_machines', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The tasks asked for, one or several, in one shape.
     *
     * @return array<int, array{description: ?string, product_id: ?int, quantity: int, title: ?string,
     *                          service_order_task_id: ?int}>
     */
    private function specsOf(ToolCall $call): array
    {
        $listed = array_filter((array) $call->argument('tasks', []), 'is_array');

        return $listed === []
            ? [$this->specFrom($call)]
            : array_values(array_map(fn (array $task) => $this->specFrom($call->withArguments($task)), $listed));
    }

    /**
     * @return array{description: ?string, product_id: ?int, quantity: int, title: ?string,
     *               service_order_task_id: ?int}
     */
    private function specFrom(ToolCall $call): array
    {
        return [
            'description' => $call->stringArgument('description') ?: null,
            'product_id' => $call->integerArgument('product_id'),
            'quantity' => $call->integerArgument('quantity') ?? 1,
            'title' => $call->stringArgument('title') ?: null,
            'service_order_task_id' => $call->integerArgument('service_order_task_id'),
        ];
    }
}
