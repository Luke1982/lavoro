<?php

namespace App\Domain\Tools\Write;

use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTask;
use App\Models\ServiceOrderTaskInstance;
use App\Models\User;

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
            ],
            'required' => ['service_order_id'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('addTask', ServiceOrder::class);
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

    public function previewOf(ToolCall $call): string
    {
        $product = Product::find($call->integerArgument('product_id'));
        $quantity = $call->integerArgument('quantity') ?? 1;

        return 'Taak op werkbon #' . $call->integerArgument('service_order_id')
            . ': ' . ($call->stringArgument('title') ?: $call->stringArgument('description') ?: 'taak')
            . ($product ? ' — ' . $quantity . 'x ' . $product->display_name : '');
    }

    public function execute(ToolCall $call): ToolResult
    {
        $order_id = $call->integerArgument('service_order_id');
        $order = $order_id === null ? null : ServiceOrder::visibleTo($call->user)->whereKey($order_id)->first();

        if ($order === null) {
            return ToolResult::notFound('Werkbon #' . ($order_id ?? '?'));
        }

        $description = $call->stringArgument('description');
        $task_id = $call->integerArgument('service_order_task_id');

        /**
         * The same rule the form applies: a task is either one of the standard
         * ones or it says in its own words what to do. Neither, and it is a line
         * on a job sheet that means nothing to whoever reads it.
         */
        if (blank($description) && $task_id === null) {
            return ToolResult::failed('Geef een omschrijving, of kies een bestaande standaardtaak.');
        }

        if ($task_id !== null && !ServiceOrderTask::whereKey($task_id)->exists()) {
            return ToolResult::notFound('Standaardtaak #' . $task_id);
        }

        $product_id = $call->integerArgument('product_id');
        $product = $product_id === null ? null : Product::visibleTo($call->user)->whereKey($product_id)->first();

        if ($product_id !== null && $product === null) {
            return ToolResult::notFound('Product #' . $product_id);
        }

        $quantity = $call->integerArgument('quantity') ?? 1;

        if ($quantity < 1 || $quantity > 999) {
            return ToolResult::failed('Het aantal moet tussen 1 en 999 liggen.');
        }

        $instance = ServiceOrderTaskInstance::create([
            'service_order_id' => $order->id,
            'service_order_task_id' => $task_id,
            'product_id' => $product?->id,
            'quantity' => $quantity,
            'title' => $call->stringArgument('title'),
            'description' => $description,
        ]);

        return ToolResult::ok(
            [
                'task_instance_id' => $instance->id,
                'service_order_id' => $order->id,
                'description' => $instance->description,
                'product' => $product?->display_name,
                'quantity' => $instance->quantity,
                'link' => '/serviceorders/' . $order->id,
            ],
            'Taak toegevoegd aan werkbon #' . $order->id
            . ($product ? ' (' . $quantity . 'x ' . $product->display_name . ')' : '') . '.',
        );
    }
}
