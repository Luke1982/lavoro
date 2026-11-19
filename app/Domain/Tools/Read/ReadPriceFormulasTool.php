<?php

namespace App\Domain\Tools\Read;

use App\Domain\Pricing\CompactFormula;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\MaintenanceContract;
use App\Models\PriceFormula;
use App\Models\User;

/**
 * What the assistant needs before it builds or changes a price formula: the
 * blocks there are, the formulas there are, and one of them as blocks and wires.
 * The blocks come along every time, because a formula written from memory of
 * what blocks probably exist is one that fails on the first wire.
 */
class ReadPriceFormulasTool implements Tool
{
    public static function name(): string
    {
        return 'price_formulas';
    }

    public function description(): string
    {
        return 'Leest de prijsformules van onderhoudscontracten: de bouwblokken, de bestaande formules, en met '
            . 'formula_id één formule als blokken en lijnen. Roep dit eerst aan als iemand een prijsformule wil '
            . 'maken, wijzigen of uitgelegd krijgen.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'formula_id' => ['type' => 'integer', 'description' => 'Eén formule helemaal lezen.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('viewAny', PriceFormula::class);
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public static function difficulty(): int
    {
        return 3;
    }

    public static function availableTo(): array
    {
        return [ToolProfile::planner, ToolProfile::administrator];
    }

    public function execute(ToolCall $call): ToolResult
    {
        $formulas = PriceFormula::with('latestVersion')->withCount('contracts')->orderBy('name')->get();
        $content = [
            'werking' => 'Blokken zijn verbonden met lijnen van een uitgang naar een ingang van dezelfde soort: '
                . 'machines of number. Meer lijnen op één ingang tellen op. Alleen wat in output_periodic (prijs '
                . 'per prijsperiode van het contract) en output_one_time aankomt, telt. Werk met de rest-uitgang '
                . 'van een filter verder, zodat elke machine één prijs krijgt. Bedragen zijn euro\'s exclusief btw. '
                . 'Probeer een formule met try_price_formula en stel hem dan voor met save_price_formula.',
            'blokken' => CompactFormula::catalogue(),
            'formules' => $formulas->map(fn (PriceFormula $formula) => [
                'formula_id' => $formula->id,
                'naam' => $formula->name,
                'omschrijving' => $formula->description,
                'versie' => $formula->latestVersion?->number,
                'contracten' => $formula->contracts_count,
            ])->all(),
        ];

        $id = $call->integerArgument('formula_id');

        if ($id !== null) {
            $formula = $formulas->firstWhere('id', $id);

            if ($formula === null) {
                return ToolResult::notFound('Prijsformule #' . $id);
            }

            $content['formule'] = [
                'formula_id' => $formula->id,
                'naam' => $formula->name,
                'versie' => $formula->latestVersion?->number,
                'link' => '/priceformulas/' . $formula->id,
                ...app(CompactFormula::class)->fromGraph($formula->latestVersion?->graph ?? []),
                'contracten_op_oudere_versie' => $call->user->can('seeFinancials', MaintenanceContract::class)
                    ? $formula->contracts()
                        ->where('price_formula_version_id', '!=', $formula->latestVersion?->id)
                        ->notEnded()
                        ->count()
                    : null,
            ];
        }

        return ToolResult::ok($content, count($formulas) . ' prijsformule(s) gelezen.');
    }
}
