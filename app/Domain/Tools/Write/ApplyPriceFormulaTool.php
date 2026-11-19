<?php

namespace App\Domain\Tools\Write;

use App\Actions\Pricing\LinkContractPriceFormulaAction;
use App\Actions\Pricing\UpgradeContractsToLatestVersionAction;
use App\Domain\Pricing\ContractPricing;
use App\Domain\Tools\ChecksBeforeConfirming;
use App\Domain\Tools\Concerns\BuildsPriceFormulas;
use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\MaintenanceContract;
use App\Models\MaintenanceContractTemplate;
use App\Models\PriceFormula;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Puts a price formula to work: on contracts, on contract templates, and moving
 * the contracts still running on an older version to the newest one. Every
 * contract it touches gets a new price, so the confirmation shows each one, old
 * and new, and the same rights apply as on the screens.
 */
class ApplyPriceFormulaTool implements ChecksBeforeConfirming, Confirmable, Tool
{
    use BuildsPriceFormulas;

    public static function name(): string
    {
        return 'apply_price_formula';
    }

    public function description(): string
    {
        return 'Zet een prijsformule op onderhoudscontracten en contractsjablonen, of werkt de lopende contracten '
            . 'op een oudere versie bij naar de nieuwste. Contracten krijgen de nieuwste versie en een nieuwe '
            . 'prijs. Er gebeurt nog niets: de gebruiker krijgt een bevestigingsknop.';
    }

    public function inputSchema(): array
    {
        $ids = fn (string $description) => [
            'type' => 'array',
            'items' => ['type' => 'integer'],
            'description' => $description,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'formula_id' => ['type' => 'integer', 'description' => 'De prijsformule.'],
                'contract_ids' => $ids('Contracten die deze formule krijgen.'),
                'template_ids' => $ids('Contractsjablonen die deze formule krijgen.'),
                'upgrade_outdated' => [
                    'type' => 'boolean',
                    'description' => 'Alle lopende contracten op een oudere versie bijwerken.',
                ],
            ],
            'required' => ['formula_id'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        if (!$user->can('seeFinancials', MaintenanceContract::class)) {
            return false;
        }

        $contracts = MaintenanceContract::whereKey($arguments['contract_ids'] ?? [])->get();
        $templates = MaintenanceContractTemplate::whereKey($arguments['template_ids'] ?? [])->get();
        $formula = PriceFormula::find($arguments['formula_id'] ?? null);
        $upgrades = (bool) ($arguments['upgrade_outdated'] ?? false);

        return $contracts->every(fn (MaintenanceContract $contract) => $user->can('update', $contract))
            && $templates->every(fn (MaintenanceContractTemplate $template) => $user->can('update', $template))
            && (!$upgrades || ($formula !== null
                && $user->can('update', $formula)
                && $user->can('update', new MaintenanceContract)));
    }

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
        return [ToolProfile::planner, ToolProfile::administrator];
    }

    public function refusalBeforeConfirming(ToolCall $call): ?ToolResult
    {
        $formula = $this->formulaOf($call);

        if ($formula?->latestVersion === null) {
            return ToolResult::notFound('Prijsformule #' . ($call->integerArgument('formula_id') ?? '?'));
        }

        $contracts = $this->contractsOf($call);
        $templates = $this->templatesOf($call);
        $missing_contracts = array_diff($call->integerListArgument('contract_ids'), $contracts->modelKeys());
        $missing_templates = array_diff($call->integerListArgument('template_ids'), $templates->modelKeys());

        return match (true) {
            $missing_contracts !== [] => ToolResult::notFound((count($missing_contracts) === 1 ? 'Contract ' : 'Contracten ') . implode(', ', $missing_contracts)),
            $missing_templates !== [] => ToolResult::notFound((count($missing_templates) === 1 ? 'Sjabloon ' : 'Sjablonen ') . implode(', ', $missing_templates)),
            /** Every contract with its price now and with the newest version: nobody agrees to a number unseen. */
            $this->affected($call, $formula)->isEmpty() && $templates->isEmpty() => ToolResult::failed(
                'Er is niets bij te werken: er zijn geen contracten of sjablonen opgegeven, en er staat geen lopend '
                . 'contract op een oudere versie.'
            ),
            default => null,
        };
    }

    /** Every contract with its price now and with the formula's newest version: nobody agrees to a number unseen. */
    public function previewOf(ToolCall $call): string
    {
        $formula = $this->formulaOf($call);
        $pricing = app(ContractPricing::class);
        $lines = [];

        foreach ($this->affected($call, $formula) as $contract) {
            $new = $pricing->evaluate($contract, $formula->latestVersion);
            $lines[] = $contract->display_title . ': ' . $this->euro((float) $contract->getRawOriginal('price'))
                . ' → ' . $this->euro($new['periodic'] ?? 0.0) . ' per periode'
                . ($new['one_time'] !== null ? ', eenmalig ' . $this->euro($new['one_time']) : '');
        }

        foreach ($this->templatesOf($call) as $template) {
            $lines[] = 'Sjabloon ' . $template->name . ' krijgt deze formule.';
        }

        return 'Prijsformule "' . $formula->name . '" (versie ' . $formula->latestVersion->number . ') toepassen:'
            . "\n- " . implode("\n- ", $lines);
    }

    public function execute(ToolCall $call): ToolResult
    {
        if ($refusal = $this->refusalBeforeConfirming($call)) {
            return $refusal;
        }

        $formula = $this->formulaOf($call);
        $templates = $this->templatesOf($call);

        /** All of it or nothing: a refusal halfway must not leave some contracts moved and others not. */
        [$prices, $upgraded] = DB::transaction(function () use ($call, $formula, $templates) {
            $link = app(LinkContractPriceFormulaAction::class);
            $prices = [];

            foreach ($this->contractsOf($call) as $contract) {
                $link->execute($contract, $formula->latestVersion);
                $prices[] = $contract->display_title . ': ' . $this->euro((float) $contract->fresh()->price);
            }

            $upgraded = $call->argument('upgrade_outdated')
                ? app(UpgradeContractsToLatestVersionAction::class)->execute($formula)
                : 0;

            $templates->each->update(['price_formula_id' => $formula->id]);

            return [$prices, $upgraded];
        });

        $summary = 'Prijsformule "' . $formula->name . '" toegepast op '
            . $this->counted(count($prices), 'contract', 'contracten')
            . ($upgraded ? ', ' . $this->counted($upgraded, 'contract', 'contracten') . ' bijgewerkt naar de nieuwste versie' : '')
            . ' en ' . $this->counted($templates->count(), 'sjabloon', 'sjablonen') . '. De nieuwe prijzen staan op de tijdlijn van '
            . 'elk contract.';

        return ToolResult::ok([
            'formula_id' => $formula->id,
            'contracten' => $prices,
            'bijgewerkt' => $upgraded,
            'sjablonen' => $templates->pluck('name')->all(),
            'link' => '/priceformulas/' . $formula->id,
        ], $summary);
    }

    private function formulaOf(ToolCall $call): ?PriceFormula
    {
        return PriceFormula::with('latestVersion')->find($call->integerArgument('formula_id'));
    }

    /** @return Collection<int, MaintenanceContract> */
    private function contractsOf(ToolCall $call): Collection
    {
        return MaintenanceContract::with('customer')->whereKey($call->integerListArgument('contract_ids'))->get();
    }

    /** @return Collection<int, MaintenanceContractTemplate> */
    private function templatesOf(ToolCall $call): Collection
    {
        return MaintenanceContractTemplate::whereKey($call->integerListArgument('template_ids'))->get();
    }

    /**
     * The contracts named, and with upgrade_outdated those still running on an
     * older version; an ended one keeps the price it ended with, as on the
     * formula's page.
     *
     * @return Collection<int, MaintenanceContract>
     */
    private function affected(ToolCall $call, PriceFormula $formula): Collection
    {
        $contracts = $this->contractsOf($call);

        if (!$call->argument('upgrade_outdated')) {
            return $contracts;
        }

        $outdated = $formula->contracts()->with('customer')
            ->where('price_formula_version_id', '!=', $formula->latestVersion->id)
            ->notEnded()
            ->get();

        return $contracts->concat($outdated)->unique('id')->values();
    }
}
