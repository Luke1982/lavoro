<?php

namespace App\Domain\Tray\Paste;

use App\Actions\Contracts\AttachContractAssetAction;
use App\Actions\Contracts\DetachContractAssetAction;
use App\Domain\Tray\Kinds\AssetKind;
use App\Exceptions\Refusal;
use App\Models\Asset;
use App\Models\MaintenanceContract;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Machines dropped on a maintenance contract: they come under it, following the
 * contract's own frequency. Only linking, so it happens at once and can be undone.
 */
class AssetsOntoContract implements UndoablePasteHandler
{
    public function question(Paste $paste): ?Question
    {
        $this->toAttach($paste, DropTarget::contract($paste));

        return null;
    }

    public function perform(Paste $paste): PasteResult
    {
        $contract = DropTarget::contract($paste);
        $assets = $this->toAttach($paste, $contract);

        Gate::forUser($paste->user)->authorize('attachAsset', $contract);

        $assets->each(fn (Asset $asset) => app(AttachContractAssetAction::class)->execute($contract, $asset));

        $message = $assets->count() === 1
            ? AssetKind::named($assets->first()) . ' valt nu onder ' . $contract->display_title . '.'
            : $assets->count() . ' machines vallen nu onder ' . $contract->display_title . '.';
        $message .= AssetKind::leftAlone($paste->records->diff($assets), 'viel er al onder', 'vielen er al onder');

        return new PasteResult($message, undo: [
            'maintenancecontract_id' => $contract->id,
            'assetable_ids' => $contract->assets()->newPivotQuery()
                ->whereIn('asset_id', $assets->pluck('id'))
                ->pluck('assetables.id')
                ->all(),
        ]);
    }

    public function undo(User $user, array $undo): string
    {
        $contract = MaintenanceContract::findOrFail($undo['maintenancecontract_id']);
        $gate = Gate::forUser($user);

        if ($gate->denies('view', $contract)) {
            throw new Refusal('Dit contract kun je niet meer openen.');
        }

        $gate->authorize('detachAsset', $contract);

        $assetable_ids = $contract->assets()->newPivotQuery()
            ->whereIn('assetables.id', $undo['assetable_ids'])
            ->pluck('assetables.id');

        $assetable_ids->each(fn ($assetable_id) => app(DetachContractAssetAction::class)->execute(
            $contract,
            (int) $assetable_id,
        ));

        return ($assetable_ids->count() === 1 ? 'De machine is' : 'De machines zijn')
            . ' weer van ' . $contract->display_title . ' af.';
    }

    /**
     * The machines not on this contract yet. A contract is drawn up for a
     * customer's own machines; a part comes along with the machine it is in.
     *
     * @return Collection<int, Asset>
     */
    private function toAttach(Paste $paste, MaintenanceContract $contract): Collection
    {
        AssetKind::refuseParts($paste->records, 'komt alleen met die machine onder een contract');

        $present = $contract->assets()->pluck('assets.id');
        $assets = $paste->records->reject(fn (Asset $asset) => $present->contains($asset->id))->values();

        if ($assets->isEmpty()) {
            throw new Refusal($paste->these('valt', 'vallen') . ' al onder dit contract.');
        }

        $assets->each(function (Asset $asset) use ($contract) {
            if ((int) $asset->customer_id !== (int) $contract->customer_id) {
                throw new Refusal(AssetKind::named($asset) . ' hoort niet bij de klant van dit contract.');
            }
        });

        return $assets;
    }
}
