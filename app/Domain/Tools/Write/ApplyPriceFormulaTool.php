<?php

namespace App\Domain\Tools\Write;

use App\Actions\Contracts\RecalculateContractPriceAction;
use App\Actions\Pricing\LinkContractPriceFormulaAction;
use App\Actions\Pricing\UpgradeContractsToLatestVersionAction;
use App\Domain\Pricing\PricedMachines;
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
use App\Support\Counted;
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
            . 'op een oudere versie bij naar de nieuwste. Er gebeurt nog niets: de gebruiker krijgt een '
            . 'bevestigingsknop.';
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
                    'description' => 'Lopende contracten op een oudere versie bijwerken.',
                ],
                'version' => ['type' => 'integer', 'description' => 'Versie van de formule die je las.'],
            ],
            'required' => ['formula_id', 'version'],
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
        $upgrades = ToolCall::booleanFrom($arguments['upgrade_outdated'] ?? false);

        return $contracts->every(fn (MaintenanceContract $contract) => $user->can('update', $contract))
            && $templates->every(fn (MaintenanceContractTemplate $template) => $user->can('update', $template))
            && (!$upgrades || ($formula !== null && $user->can('upgradeContracts', $formula)));
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

    /**
     * Refused when the formula has a newer version than the one the assistant
     * read: the prices it would confirm are not the prices that would be saved.
     */
    public function refusalBeforeConfirming(ToolCall $call): ?ToolResult
    {
        $formula = $this->formulaOf($call);

        if ($formula?->latestVersion === null) {
            return ToolResult::notFound('Prijsformule #' . ($call->integerArgument('formula_id') ?? '?'));
        }

        $read_version = $call->integerArgument('version');
        $contracts = $this->contractsOf($call);
        $templates = $this->templatesOf($call);
        $missing_contracts = array_diff($call->integerListArgument('contract_ids'), $contracts->modelKeys());
        $missing_templates = array_diff($call->integerListArgument('template_ids'), $templates->modelKeys());

        return match (true) {
            $read_version !== $formula->latestVersion->number => ToolResult::failed(
                'Prijsformule "' . $formula->name . '" staat nu op versie ' . $formula->latestVersion->number
                . ', niet op versie ' . ($read_version ?? '?') . '. Lees hem opnieuw met price_formulas en pas dan die '
                . 'versie toe.'
            ),
            $missing_contracts !== [] => ToolResult::notFound((count($missing_contracts) === 1 ? 'Contract ' : 'Contracten ') . implode(', ', $missing_contracts)),
            $missing_templates !== [] => ToolResult::notFound((count($missing_templates) === 1 ? 'Sjabloon ' : 'Sjablonen ') . implode(', ', $missing_templates)),
            $this->affected($call, $formula)->isEmpty() && $templates->isEmpty() => ToolResult::failed(
                'Er is niets bij te werken: er zijn geen contracten of sjablonen opgegeven, en er staat geen lopend '
                . 'contract op een oudere versie.'
            ),
            default => null,
        };
    }

    /**
     * Every contract with its price now and with the formula's newest version:
     * nobody agrees to a number unseen. The new price is asked of the rule that
     * stores it, so a formula coming out below nothing shows the €0,00 the
     * contract will carry, and one that cannot be read shows the price staying.
     */
    public function previewOf(ToolCall $call): string
    {
        $formula = $this->formulaOf($call);
        $recalculate = app(RecalculateContractPriceAction::class);
        $affected = $this->affected($call, $formula);
        $lines = [];

        PricedMachines::preload($affected);

        foreach ($affected as $contract) {
            $old = $contract->getRawOriginal('price') === null ? null : (float) $contract->getRawOriginal('price');
            $old_one_time = $contract->getRawOriginal('one_time_price');
            $new = $recalculate->pricesFor($contract, even_if_ended: true, version: $formula->latestVersion);

            $lines[] = $contract->display_title . ': ' . ($new === null
                ? 'prijs blijft ' . $this->euro($old) . ' per periode: versie ' . $formula->latestVersion->number
                    . ' bevat een blok dat niet gelezen kan worden, dus er wordt geen prijs berekend'
                : $this->euro($old) . ' → ' . $this->euro($new['price'] / 100) . ' per periode'
                    . ($new['one_time'] === null && $old_one_time === null ? '' : ', eenmalig '
                        . $this->euro($old_one_time === null ? null : (float) $old_one_time) . ' → '
                        . $this->euro($new['one_time'] === null ? null : $new['one_time'] / 100)));
        }

        foreach ($this->templatesOf($call) as $template) {
            $lines[] = 'Sjabloon ' . $template->name . ' krijgt deze formule.';
        }

        return 'Prijsformule "' . $formula->name . '" (versie ' . $formula->latestVersion->number . ') toepassen:'
            . "\n- " . implode("\n- ", $lines);
    }

    /** All of it or nothing: a refusal halfway must not leave some contracts moved and others not. */
    public function execute(ToolCall $call): ToolResult
    {
        if ($refusal = $this->refusalBeforeConfirming($call)) {
            return $refusal;
        }

        $formula = $this->formulaOf($call);
        $contracts = $this->contractsOf($call);
        $templates = $this->templatesOf($call);

        $upgraded = DB::transaction(function () use ($call, $formula, $contracts, $templates) {
            app(LinkContractPriceFormulaAction::class)->executeMany($contracts, $formula->latestVersion);

            $upgraded = $call->booleanArgument('upgrade_outdated')
                ? app(UpgradeContractsToLatestVersionAction::class)->execute($formula)->count
                : 0;

            $templates->each->update(['price_formula_id' => $formula->id]);

            return $upgraded;
        });

        $prices = $contracts
            ->map(fn (MaintenanceContract $contract) => $contract->display_title . ': '
                . $this->euro((float) $contract->fresh()->price))
            ->all();

        $summary = 'Prijsformule "' . $formula->name . '" toegepast op '
            . Counted::of(count($prices), 'contract', 'contracten')
            . ($upgraded ? ', ' . Counted::of($upgraded, 'contract', 'contracten') . ' bijgewerkt naar de nieuwste versie' : '')
            . ' en ' . Counted::of($templates->count(), 'sjabloon', 'sjablonen') . '. De nieuwe prijzen staan op de tijdlijn van '
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
        return MaintenanceContract::with('customer')->whereKey($call->integerListArgument('contract_ids'))->orderBy('id')->get();
    }

    /** @return Collection<int, MaintenanceContractTemplate> */
    private function templatesOf(ToolCall $call): Collection
    {
        return MaintenanceContractTemplate::whereKey($call->integerListArgument('template_ids'))->orderBy('id')->get();
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

        if (!$call->booleanArgument('upgrade_outdated')) {
            return $contracts;
        }

        $outdated = $formula->contractsBehind()->with('customer')->notEnded()->orderBy('maintenance_contracts.id')->get();

        return $contracts->concat($outdated)->unique('id')->sortBy('id')->values();
    }
}
