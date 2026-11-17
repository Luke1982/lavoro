<?php

namespace App\Domain\Tray\Paste;

use App\Actions\Assets\AttachChildAssetAction;
use App\Domain\Tray\Kinds\AssetKind;
use App\Exceptions\Refusal;
use App\Models\Asset;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Machines dropped on another machine: they go under it as a part, the same way
 * "Machine koppelen" on the machine screen does. Only linking, so it happens at
 * once and can be undone.
 */
class AssetsOntoAsset implements UndoablePasteHandler
{
    public function question(Paste $paste): ?Question
    {
        $parent = DropTarget::asset($paste);
        $paste->records->each(fn (Asset $child) => $this->check($parent, $child));

        return null;
    }

    public function perform(Paste $paste): PasteResult
    {
        $parent = DropTarget::asset($paste);
        Gate::forUser($paste->user)->authorize('attachChild', $parent);

        $locations = $paste->records->mapWithKeys(fn (Asset $child) => [$child->id => $child->location_id]);

        $paste->records->each(fn (Asset $child) => app(AttachChildAssetAction::class)->execute($parent, $child));

        $message = $paste->records->count() === 1
            ? AssetKind::title($paste->records->first()) . ' zit nu in ' . AssetKind::title($parent) . '.'
            : $paste->records->count() . ' machines zitten nu in ' . AssetKind::title($parent) . '.';

        return new PasteResult($message, undo: [
            'parent_asset_id' => $parent->id,
            'locations' => $locations->all(),
        ]);
    }

    /**
     * Cuts them loose again and puts each back where it stood, as long as that
     * place still belongs to the customer; otherwise where the parent stands, as
     * "Koppeling verwijderen" would.
     */
    public function undo(User $user, array $undo): string
    {
        $parent = Asset::visibleTo($user)->findOrFail($undo['parent_asset_id']);
        Gate::forUser($user)->authorize('detachParent', $parent);

        $root = $parent->rootAsset();
        $children = Asset::visibleTo($user)
            ->where('parent_asset_id', $parent->id)
            ->whereIn('id', array_keys($undo['locations']))
            ->get();

        $children->each(function (Asset $child) use ($root, $undo) {
            $location_id = $undo['locations'][$child->id] ?? null;
            $belongs = $location_id !== null
                && Location::whereKey($location_id)->where('customer_id', $root->customer_id)->exists();

            $child->update([
                'parent_asset_id' => null,
                'product_relation_id' => null,
                'productable_id' => null,
                'customer_id' => $root->customer_id,
                'location_id' => $belongs ? $location_id : $root->location_id,
            ]);
        });

        return ($children->count() === 1 ? 'De machine staat' : 'De machines staan')
            . ' weer op zichzelf.';
    }

    private function check(Asset $parent, Asset $child): void
    {
        if ($reason = app(AttachChildAssetAction::class)->refusal($parent, $child)) {
            throw new Refusal(AssetKind::title($child) . ': ' . lcfirst($reason));
        }
    }
}
