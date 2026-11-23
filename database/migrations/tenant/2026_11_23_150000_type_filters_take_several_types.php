<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Filter op type" now takes several types. A stored filter with one type gets
 * a list holding that type, so every formula prices exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('price_formula_versions')->orderBy('id')->each(function (object $version) {
            $graph = json_decode($version->graph, true);
            $changed = false;

            foreach ($graph['nodes'] ?? [] as $index => $node) {
                if (($node['type'] ?? null) !== 'filter_type' || !array_key_exists('product_type_id', $node['data'] ?? [])) {
                    continue;
                }

                $type_id = $node['data']['product_type_id'];
                unset($graph['nodes'][$index]['data']['product_type_id']);
                $graph['nodes'][$index]['data']['product_type_ids'] = $type_id === null || $type_id === '' ? [] : [(int) $type_id];
                $changed = true;
            }

            if ($changed) {
                DB::table('price_formula_versions')->where('id', $version->id)->update(['graph' => json_encode($graph)]);
            }
        });
    }

    public function down(): void {}
};
