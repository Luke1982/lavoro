<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An asset is owned either by a customer or by a parent asset, never both and never
 * neither. Folding asset_relations onto the assets table makes the single parent
 * structural and lets the ownership rule be a real CHECK constraint, so a transfer
 * only ever rewrites customer_id on a root and the whole subtree follows.
 *
 * The CHECK is MySQL-only: SQLite cannot add a constraint to an existing table, and
 * the test suite runs on SQLite. Asset::saving() enforces the same rule on every
 * driver, so the invariant stays covered by tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('parent_asset_id')->nullable()->after('customer_id')
                ->constrained('assets')->cascadeOnDelete();
            $table->foreignId('product_relation_id')->nullable()->after('parent_asset_id')
                ->constrained('product_relations')->nullOnDelete();
            $table->foreignId('productable_id')->nullable()->after('product_relation_id')
                ->constrained('productables')->nullOnDelete();
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreignIdFor(Customer::class)->nullable()->change();
        });

        if (Schema::hasTable('asset_relations')) {
            foreach (DB::table('asset_relations')->orderBy('id')->get() as $relation) {
                DB::table('assets')->where('id', $relation->child_asset_id)->update([
                    'parent_asset_id' => $relation->parent_asset_id,
                    'product_relation_id' => $relation->product_relation_id,
                    'productable_id' => $relation->productable_id,
                    'customer_id' => null,
                    'location_id' => null,
                ]);
            }
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE assets ADD CONSTRAINT chk_asset_owner'
                . ' CHECK ((customer_id IS NULL) <> (parent_asset_id IS NULL))'
            );
        }

        Schema::dropIfExists('asset_relations');
    }

    public function down(): void
    {
        Schema::create('asset_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('child_asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('productable_id')->nullable()->nullOnDelete()->constrained('productables');
            $table->foreignId('product_relation_id')->nullable()->nullOnDelete()->constrained('product_relations');
            $table->timestamps();
        });

        $now = now();

        foreach (DB::table('assets')->whereNotNull('parent_asset_id')->orderBy('id')->get() as $child) {
            DB::table('asset_relations')->insert([
                'parent_asset_id' => $child->parent_asset_id,
                'child_asset_id' => $child->id,
                'productable_id' => $child->productable_id,
                'product_relation_id' => $child->product_relation_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE assets DROP CONSTRAINT chk_asset_owner');
        }

        $this->restoreOwnershipFromRoots();

        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_asset_id');
            $table->dropConstrainedForeignId('product_relation_id');
            $table->dropConstrainedForeignId('productable_id');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreignIdFor(Customer::class)->nullable(false)->change();
        });
    }

    /**
     * Children carry no owner of their own, so before the columns disappear every
     * descendant has to inherit the customer and location of the root it hangs under.
     */
    private function restoreOwnershipFromRoots(): void
    {
        do {
            $updated = 0;

            $orphans = DB::table('assets')
                ->whereNull('customer_id')
                ->whereNotNull('parent_asset_id')
                ->get();

            foreach ($orphans as $orphan) {
                $parent = DB::table('assets')->where('id', $orphan->parent_asset_id)->first();

                if (!$parent || $parent->customer_id === null) {
                    continue;
                }

                DB::table('assets')->where('id', $orphan->id)->update([
                    'customer_id' => $parent->customer_id,
                    'location_id' => $parent->location_id,
                ]);

                $updated++;
            }
        } while ($updated > 0);
    }
};
