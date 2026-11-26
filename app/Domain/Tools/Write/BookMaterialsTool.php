<?php

namespace App\Domain\Tools\Write;

use App\Domain\Tools\ChecksBeforeConfirming;
use App\Domain\Tools\Confirmable;
use App\Domain\Tools\SealsWhatWasAgreed;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Domain\Tools\Write\Concerns\ResolvesBeforeConfirming;
use App\Domain\Tools\Write\Concerns\ValidatesLikeTheForm;
use App\Exceptions\Refusal;
use App\Http\Requests\FreeformMaterialStoreRequest;
use App\Http\Requests\ServiceOrderAttachMaterialRequest;
use App\Models\Material;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\MateriableService;
use App\Services\TaskInstanceVisibility;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Books what was used on a werkbon, or on one of its tasks: "twee meter
 * koelleiding en drie bochten".
 *
 * The one field on the werkbon does two things and so does this: a material
 * from the catalogue takes stock with it, a line in free text does not. Both go
 * through MateriableService, the same as the screen, so the stock moves and the
 * werkbon's timeline says so exactly as when it was typed in. Who may book is
 * asked of the two form requests, and whether the werkbon or task still takes
 * materials of MateriableService, so neither rule has a copy here.
 *
 * The unit somebody said is checked against the material's own. Koelleiding
 * kept per roll and booked as "2" is two rolls off the shelf, and nothing about
 * that line would look wrong afterwards.
 */
class BookMaterialsTool implements ChecksBeforeConfirming, Confirmable, SealsWhatWasAgreed, Tool
{
    use ResolvesBeforeConfirming;
    use ValidatesLikeTheForm;

    /** One answer's worth of what was used; a longer list is a stock count, not a werkbon. */
    private const MOST_LINES = 25;

    private const SAME_UNIT = [
        'st' => 'stuk', 'stk' => 'stuk', 'stuks' => 'stuk', 'x' => 'stuk', 'pcs' => 'stuk',
        'm' => 'meter', 'mtr' => 'meter', 'meters' => 'meter', 'm1' => 'meter',
    ];

    public static function name(): string
    {
        return 'book_materials';
    }

    public function description(): string
    {
        return 'Boekt gebruikt materiaal op een werkbon of een taak ervan; de voorraad gaat mee omlaag. '
            . 'Zoek het eerst met find_materials. Niet in de catalogus: description zonder material_id '
            . '(vrije regel). Zet de genoemde eenheid in unit.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'service_order_id' => ['type' => 'integer', 'description' => 'De werkbon.'],
                'task_instance_id' => ['type' => 'integer', 'description' => 'Op deze taak van de werkbon.'],
                'lines' => [
                    'type' => 'array',
                    'description' => 'Wat er gebruikt is.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'material_id' => ['type' => 'integer'],
                            'description' => ['type' => 'string'],
                            'quantity' => ['type' => 'number'],
                            'unit' => ['type' => 'string'],
                            'unforeseen' => ['type' => 'boolean'],
                        ],
                        'required' => ['quantity'],
                    ],
                ],
            ],
            'required' => ['service_order_id', 'lines'],
            'additionalProperties' => false,
        ];
    }

    /**
     * The questions the screen asks, put to the screen's own form requests:
     * attaching a catalogue material, and writing a free line. Bound to the
     * werkbon also for a line on one of its tasks, which those requests answer
     * through the werkbon anyway.
     *
     * Lines are told apart by isCatalogueLine(), the same as resolve() books
     * them, so a line cannot pass here as one kind and be booked as the other.
     */
    public function authorize(User $user, array $arguments): bool
    {
        $order = ServiceOrder::find((int) ($arguments['service_order_id'] ?? 0));

        if ($order === null) {
            return true;
        }

        $lines = array_filter((array) ($arguments['lines'] ?? []), 'is_array');
        $catalogue = array_filter($lines, fn (array $line) => $this->isCatalogueLine($line));
        $route = ['serviceorder' => $order];

        return ($catalogue === [] || $this->allowedAsTheFormWould(ServiceOrderAttachMaterialRequest::class, $user, $route))
            && (count($catalogue) === count($lines) || $this->allowedAsTheFormWould(FreeformMaterialStoreRequest::class, $user, $route));
    }

    /** Wat er gebruikt is, met aantal en eenheid, uit een zin halen en aan materialen koppelen. */
    public static function difficulty(): int
    {
        return 4;
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public static function availableTo(): array
    {
        return ToolProfile::all();
    }

    public function previewOf(ToolCall $call): string
    {
        $resolved = $this->resolution($call);

        if ($resolved instanceof ToolResult) {
            return 'Materiaal boeken op werkbon #' . $call->integerArgument('service_order_id') . '.';
        }

        $stock = [];

        $lines = array_map(function (array $line) use (&$stock, $resolved) {
            $material = $line['material'];

            if ($material === null) {
                return $this->lineText($line) . ' (vrije regel, geen voorraad)';
            }

            if ($material->is_service || !$resolved['sees_materials']) {
                return $this->lineText($line);
            }

            /** The same material twice takes from what the first line left. */
            $before = $stock[$material->id] ?? (float) $material->stock;
            $stock[$material->id] = $before - $line['quantity'];

            return $this->lineText($line)
                . ' (voorraad ' . Quantity::human($before) . ' → ' . Quantity::human($stock[$material->id]) . ')';
        }, $resolved['lines']);

        return 'Op ' . $this->ownerLabel($resolved) . ': ' . implode('; ', $lines);
    }

    /**
     * What was agreed: which lines, how many of what, on which werkbon or task.
     * The stock in the preview is left out, because a filter taken from the
     * shelf for another job does not change this booking.
     */
    public function sealOf(ToolCall $call): string
    {
        $resolved = $this->resolution($call);

        if ($resolved instanceof ToolResult) {
            return 'onuitvoerbaar: ' . ($resolved->summary ?? '');
        }

        return json_encode([
            'order' => $resolved['order']->id,
            'owner' => [get_class($resolved['owner']), $resolved['owner']->getKey()],
            'lines' => array_map(fn (array $line) => [
                $line['material']?->id,
                $line['material'] === null ? $line['description'] : null,
                $line['quantity'],
                $line['material']?->usageUnit?->name,
                $line['unforeseen'],
            ], $resolved['lines']),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function execute(ToolCall $call): ToolResult
    {
        $resolved = $this->resolution($call);

        if ($resolved instanceof ToolResult) {
            return $resolved;
        }

        $materiables = app(MateriableService::class);

        /** All of them or none: half of what was used reads as all of it. */
        try {
            $booked = DB::transaction(fn () => array_map(function (array $line) use ($materiables, $resolved) {
                $attributes = ['quantity' => $line['quantity'], 'unforseen' => $line['unforeseen']];

                if ($line['material'] === null) {
                    $materiables->createFreeform($resolved['owner'], $attributes + ['description' => $line['description']]);

                    return ['line' => $this->lineText($line), 'free_line' => true];
                }

                $materiables->attach($resolved['owner'], $line['material'], $attributes);

                return array_filter([
                    'line' => $this->lineText($line),
                    'material_id' => $line['material']->id,
                    'stock_now' => $resolved['sees_materials'] && !$line['material']->is_service
                        ? (float) $line['material']->stock
                        : null,
                ], fn ($value) => $value !== null);
            }, $resolved['lines']));
        } catch (Refusal $refusal) {
            return ToolResult::failed($refusal->getMessage());
        }

        return ToolResult::ok(
            [
                'service_order_id' => $resolved['order']->id,
                'task_instance_id' => $resolved['owner'] === $resolved['order'] ? null : $resolved['owner']->id,
                'booked' => $booked,
                'link' => '/serviceorders/' . $resolved['order']->id,
            ],
            'Geboekt op ' . $this->ownerLabel($resolved) . ': '
                . implode('; ', array_column($booked, 'line')) . '.',
        );
    }

    /**
     * What goes where, or why it cannot: the werkbon or task, and every line
     * checked by the rules of the form that books it on the screen.
     *
     * @return ToolResult|array<string, mixed>
     */
    private function resolve(ToolCall $call): ToolResult|array
    {
        $order_id = $call->integerArgument('service_order_id');
        $order = ServiceOrder::visibleTo($call->user)->with('customer:id,name')->whereKey($order_id)->first();

        if ($order === null) {
            return ToolResult::notFound('Werkbon #' . ($order_id ?? '?'));
        }

        $owner = $this->ownerOf($call, $order);

        if ($owner instanceof ToolResult) {
            return $owner;
        }

        $locked_because = app(MateriableService::class)->lockedBecause($owner);

        if ($locked_because !== null) {
            return ToolResult::failed($locked_because);
        }

        $given = array_values(array_filter((array) $call->argument('lines', []), 'is_array'));

        if ($given === []) {
            return ToolResult::failed('Geef minstens één regel met een aantal.');
        }

        if (count($given) > self::MOST_LINES) {
            return ToolResult::failed('Hooguit ' . self::MOST_LINES . ' regels in één keer; boek de rest apart.');
        }

        $materials = Material::with('usageUnit:id,name')
            ->whereIn('id', array_filter(array_map(fn (array $line) => (int) ($line['material_id'] ?? 0), $given)))
            ->get()
            ->keyBy('id');

        $sees_materials = $call->user->can('viewAny', Material::class);
        $lines = [];

        foreach ($given as $line) {
            $checked = $this->checkedLine($line, $materials, $sees_materials);

            if ($checked instanceof ToolResult) {
                return $checked;
            }

            $lines[] = $checked;
        }

        return ['order' => $order, 'owner' => $owner, 'lines' => $lines, 'sees_materials' => $sees_materials];
    }

    /** The werkbon itself, or the task on it the person can see. */
    private function ownerOf(ToolCall $call, ServiceOrder $order): Model|ToolResult
    {
        $task_id = $call->integerArgument('task_instance_id');

        if ($task_id === null) {
            return $order;
        }

        $task = app(TaskInstanceVisibility::class)->visibleTo($order, $call->user)->firstWhere('id', $task_id);

        return $task ?? ToolResult::notFound('Taak #' . $task_id . ' op werkbon #' . $order->id);
    }

    /**
     * One line as the form would take it, onvoorzien or not by the form's own
     * default when nobody said.
     *
     * Named by its number to whoever may not see the materials list: booking
     * on a werkbon is not a way to read the catalogue.
     *
     * @param  array<string, mixed>  $line
     * @param  Collection<int, Material>  $materials
     * @return ToolResult|array{material: ?Material, description: string, label: string, quantity: float, unforeseen: bool}
     */
    private function checkedLine(array $line, $materials, bool $sees_materials): ToolResult|array
    {
        $description = trim((string) ($line['description'] ?? ''));

        if (!$this->isCatalogueLine($line)) {
            if ($description === '') {
                return ToolResult::failed('Geef per regel een material_id, of een description voor een vrije regel.');
            }

            return $this->asTheFormWouldTake($line, null, $description, $description);
        }

        $material_id = filter_var($line['material_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $material = $material_id === false ? null : $materials->get($material_id);

        if ($material === null) {
            return ToolResult::notFound('Materiaal #' . (is_scalar($line['material_id']) ? $line['material_id'] : '?'));
        }

        $label = $sees_materials ? $material->name : 'materiaal #' . $material->id;
        $unit_problem = $this->unitProblem($material, $label, (string) ($line['unit'] ?? ''));

        return $unit_problem ?? $this->asTheFormWouldTake($line, $material, $material->name, $label);
    }

    /**
     * A line given a material_id at all is a catalogue line, whatever the id
     * turns out to be: an id that names nothing is refused as not found rather
     * than booked as free text, which would ask a different permission than the
     * one checked for it.
     *
     * @param  array<string, mixed>  $line
     */
    private function isCatalogueLine(array $line): bool
    {
        $material_id = $line['material_id'] ?? null;

        return $material_id !== null && $material_id !== '';
    }

    /**
     * @param  array<string, mixed>  $line
     * @return ToolResult|array{material: ?Material, description: string, label: string, quantity: float, unforeseen: bool}
     */
    private function asTheFormWouldTake(array $line, ?Material $material, string $description, string $label): ToolResult|array
    {
        $said = $line['unforeseen'] ?? null;

        $data = array_filter([
            'quantity' => $line['quantity'] ?? null,
            'unforseen' => $said === null ? null : ToolCall::booleanFrom($said),
        ], fn ($value) => $value !== null) + ($material === null ? ['description' => $description] : []);

        $validator = $material === null
            ? $this->asTheFormWould(FreeformMaterialStoreRequest::class, $data)
            : $this->asTheFormWould(ServiceOrderAttachMaterialRequest::class, $data, route_parameters: ['material' => $material]);

        if ($validator->fails()) {
            return ToolResult::failed($label . ': ' . $this->complaintOf($validator));
        }

        $validated = $validator->validated();

        return [
            'material' => $material,
            'description' => $description,
            'label' => $label,
            'quantity' => (float) $validated['quantity'],
            'unforeseen' => (bool) $validated['unforseen'],
        ];
    }

    private function unitProblem(Material $material, string $label, string $said): ?ToolResult
    {
        $unit = $material->usageUnit?->name;

        if ($unit === null || trim($said) === '' || $this->sameUnit($unit, $said)) {
            return null;
        }

        return ToolResult::failed(
            $label . ' wordt geboekt per ' . $unit . ', niet per ' . trim($said) . '. '
                . 'Er is niets geboekt. Vraag hoeveel ' . $unit . ' het is; reken niet zelf om.'
        );
    }

    private function sameUnit(string $unit, string $said): bool
    {
        return $this->canonicalUnit($unit) === $this->canonicalUnit($said);
    }

    private function canonicalUnit(string $unit): string
    {
        $plain = rtrim(mb_strtolower(trim($unit)), '.');

        return self::SAME_UNIT[$plain] ?? $plain;
    }

    /** @param array{material: ?Material, description: string, label: string, quantity: float, unforeseen: bool} $line */
    private function lineText(array $line): string
    {
        $unit = $line['material']?->usageUnit?->name;

        return Quantity::human($line['quantity']) . ($unit ? ' ' . $unit . ' ' : 'x ') . $line['label']
            . ($line['unforeseen'] ? ', onvoorzien' : '');
    }

    /** @param array<string, mixed> $resolved */
    private function ownerLabel(array $resolved): string
    {
        $order = $resolved['order'];
        $task = $resolved['owner'] === $order ? '' : 'taak "' . $resolved['owner']->timelineTitle() . '" van ';

        return $task . 'werkbon #' . $order->id . ($order->customer ? ' (' . $order->customer->name . ')' : '');
    }
}
