<?php

namespace App\Domain\Tools\Write;

use App\Actions\Pricing\CreatePriceFormulaAction;
use App\Actions\Pricing\RenamePriceFormulaVersionAction;
use App\Actions\Pricing\SavePriceFormulaGraphAction;
use App\Domain\Pricing\CompactFormula;
use App\Domain\Tools\ChecksBeforeConfirming;
use App\Domain\Tools\Concerns\BuildsPriceFormulas;
use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Domain\Tools\Write\Concerns\ValidatesLikeTheForm;
use App\Http\Requests\PriceFormulaStoreRequest;
use App\Models\PriceFormula;
use App\Models\PriceFormulaVersion;
use App\Models\User;
use App\Support\Counted;
use App\Support\Money;

/**
 * Saves a price formula the assistant built from somebody's description: a new
 * one, or a new state of an existing one. The same rule as the editor's Opslaan
 * holds: a version contracts are on is never changed, so saving one makes the
 * next version, and the confirmation says which contracts keep their price.
 */
class SavePriceFormulaTool implements ChecksBeforeConfirming, Confirmable, Tool
{
    use BuildsPriceFormulas;
    use ValidatesLikeTheForm;

    public static function name(): string
    {
        return 'save_price_formula';
    }

    public function description(): string
    {
        return 'Slaat een prijsformule op: een nieuwe (met name), of een bestaande (met formula_id) met de blokken '
            . 'en lijnen die je meegeeft, die de hele formule vervangen. Probeer hem eerst met try_price_formula. '
            . 'Wordt het een nieuwe versie, vraag dan of de lopende contracten meegaan en hoe de versie heet. '
            . 'Er gebeurt nog niets: de gebruiker krijgt een bevestigingsknop.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'formula_id' => ['type' => 'integer', 'description' => 'Een bestaande formule wijzigen.'],
                'name' => ['type' => 'string', 'description' => 'Naam van een nieuwe formule, of een nieuwe naam.'],
                'description' => ['type' => 'string', 'description' => 'Waarvoor de formule is, in één zin.'],
                'version_name' => ['type' => 'string', 'description' => 'Naam van de versie, bijv. het jaar.'],
                'move_contracts' => ['type' => 'boolean', 'description' => 'Lopende contracten mee naar de nieuwe versie.'],
                ...$this->formulaProperties(),
            ],
            'required' => ['blocks', 'wires'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        if (!isset($arguments['formula_id'])) {
            return $user->can('create', PriceFormula::class);
        }

        $formula = PriceFormula::find($arguments['formula_id']);

        return $formula === null || ($user->can('update', $formula)
            && (empty($arguments['move_contracts']) || $user->can('upgradeContracts', $formula)));
    }

    /** Een omschrijving van hoe iemand wil rekenen omzetten in blokken en lijnen. */
    public static function difficulty(): int
    {
        return 7;
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public static function availableTo(): array
    {
        return [ToolProfile::planner, ToolProfile::administrator];
    }

    public function refusalBeforeConfirming(ToolCall $call): ?ToolResult
    {
        $formula = $this->formulaOf($call);
        $id = $call->integerArgument('formula_id');

        if ($id !== null && $formula === null) {
            return ToolResult::notFound('Prijsformule #' . $id);
        }

        $name = trim((string) $call->stringArgument('name'));

        if ($formula === null || ($name !== '' && $name !== $formula->name)) {
            $validator = $this->asTheFormWould(PriceFormulaStoreRequest::class, array_filter([
                'name' => $name,
                'description' => $call->stringArgument('description'),
            ], fn ($value) => $value !== null));

            if ($validator->fails()) {
                return ToolResult::failed($this->complaintOf($validator));
            }
        }

        $problems = $this->drafted($call, $formula)['problems'];

        return $problems === []
            ? null
            : ToolResult::failedWith(['fouten' => $problems], 'De formule klopt nog niet: ' . implode(' ', $problems));
    }

    /**
     * Says what saving does to the running contracts: taken along to the new
     * version with what they cost together before and after, or left on an
     * older one at the price they have now.
     */
    public function previewOf(ToolCall $call): string
    {
        $formula = $this->formulaOf($call);
        $graph = $this->drafted($call, $formula)['graph'];
        $lines = app(CompactFormula::class)->describe($graph);
        $behind = 0;
        $moving = '';

        if ($formula === null) {
            $head = 'Nieuwe prijsformule "' . trim((string) $call->stringArgument('name')) . '" aanmaken';
        } else {
            $impact = app(SavePriceFormulaGraphAction::class)
                ->impact($formula, $graph, with_prices: $call->booleanArgument('move_contracts'));
            $head = 'Prijsformule "' . $formula->name . '" opslaan';

            if (!$impact['new_version']) {
                $behind = $formula->contractsBehind()->notEnded()->count();
            } elseif (!$call->booleanArgument('move_contracts')) {
                $behind = $impact['running'];
            } elseif ($impact['running'] > 0) {
                $moving = ' ' . Counted::of($impact['running'], 'lopend contract gaat', 'lopende contracten gaan')
                    . ' mee naar de nieuwe versie: per periode samen van ' . Money::euros($impact['now'])
                    . ' naar ' . Money::euros($impact['then']) . '.';
            }

            if ($impact['new_version']) {
                $head .= ' als ' . PriceFormulaVersion::labelOf(
                    $impact['number'],
                    PriceFormulaVersion::cleanName($call->stringArgument('version_name')),
                );
            }
        }

        $head .= ' (' . Counted::of(count($lines), 'blok', 'blokken') . ').' . $moving;

        if ($behind > 0) {
            $head .= ' ' . SavePriceFormulaGraphAction::behindNotice($behind);
        }

        return $head . "\n- " . implode("\n- ", $lines);
    }

    public function execute(ToolCall $call): ToolResult
    {
        if ($refusal = $this->refusalBeforeConfirming($call)) {
            return $refusal;
        }

        $formula = $this->formulaOf($call);
        $graph = $this->drafted($call, $formula)['graph'];
        $name = trim((string) $call->stringArgument('name'));
        $description = $call->stringArgument('description');

        if ($formula === null) {
            $formula = app(CreatePriceFormulaAction::class)->execute($name, $description, $graph, $call->user);
            $version = $formula->latestVersion()->first();
            app(RenamePriceFormulaVersionAction::class)->execute($version, $call->stringArgument('version_name'));
            $message = 'Prijsformule "' . $formula->name . '" aangemaakt.';
        } else {
            $formula->update(array_filter(['name' => $name ?: null, 'description' => $description]));
            ['version' => $version, 'behind' => $behind, 'moved' => $moved] = app(SavePriceFormulaGraphAction::class)
                ->execute(
                    $formula,
                    $graph,
                    $call->user,
                    version_name: $call->stringArgument('version_name'),
                    move_contracts: $call->booleanArgument('move_contracts'),
                );
            $message = 'Prijsformule "' . $formula->name . '" opgeslagen als ' . $version->label() . '.';

            if ($moved->count > 0) {
                $message .= ' ' . $moved->notice();
            }

            if ($behind > 0) {
                $message .= ' ' . SavePriceFormulaGraphAction::behindNotice($behind)
                    . ' Bijwerken kan met apply_price_formula of op de pagina van de formule.';
            }
        }

        return ToolResult::ok([
            'formula_id' => $formula->id,
            'naam' => $formula->name,
            'versie' => $version->number,
            'versienaam' => $version->name,
            'link' => '/priceformulas/' . $formula->id,
            'what' => $version->displayName(),
        ], $message);
    }

    /** @return array{graph: array, problems: array<int, string>} */
    private function drafted(ToolCall $call, ?PriceFormula $formula): array
    {
        return $this->graphFrom(
            (array) $call->argument('blocks', []),
            (array) $call->argument('wires', []),
            $formula?->latestVersion?->graph,
        );
    }

    private function formulaOf(ToolCall $call): ?PriceFormula
    {
        $id = $call->integerArgument('formula_id');

        return $id === null ? null : PriceFormula::with('latestVersion')->find($id);
    }
}
