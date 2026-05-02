<?php

namespace App\Http\Controllers;

use App\Models\AssetRelation;
use App\Http\Requests\AssetRelationStoreRequest;

class AssetRelationController extends Controller
{
    public function store(AssetRelationStoreRequest $request)
    {
        $v = $request->validated();

        $exists = AssetRelation::where('parent_asset_id', $v['parent_asset_id'])
            ->where('child_asset_id', $v['child_asset_id'])
            ->exists();

        if ($exists) {
            return redirect()->back()->with('info', 'Deze koppeling bestaat al.');
        }

        AssetRelation::create([
            'parent_asset_id'     => $v['parent_asset_id'],
            'child_asset_id'      => $v['child_asset_id'],
            'product_relation_id' => $v['product_relation_id'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Machine gekoppeld.');
    }

    public function destroy(AssetRelation $assetrelation)
    {
        $assetrelation->delete();

        return redirect()->back()->with('success', 'Koppeling verwijderd.');
    }
}
