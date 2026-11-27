<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A product's standard materials on quotes become rows of the one list of what
 * belongs with a product, productables, beside its parts: a row that points at
 * a material, with how much per piece and whether it comes along always, is
 * asked, or is suggested. A material per piece can be a part of a metre, so the
 * quantity takes decimals; a part still comes in whole pieces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productables', function (Blueprint $table) {
            $table->decimal('quantity', 10, 2)->default(1)->change();
            $table->string('prompt', 20)->nullable()->after('flex_quantity');
            $table->unsignedInteger('position')->default(0)->after('prompt');
        });

        DB::table('productables')->insertUsing(
            ['product_id', 'productable_type', 'productable_id', 'quantity', 'prompt', 'position', 'created_at', 'updated_at'],
            DB::table('product_material_defaults')
                ->selectRaw('product_id, ?, material_id, quantity, prompt, position, created_at, updated_at', ['App\\Models\\Material'])
                ->orderBy('id'),
        );

        Schema::drop('product_material_defaults');
    }

    public function down(): void
    {
        Schema::create('product_material_defaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('prompt', 20)->default('ask');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'material_id']);
        });

        DB::table('product_material_defaults')->insertUsing(
            ['product_id', 'material_id', 'quantity', 'prompt', 'position', 'created_at', 'updated_at'],
            DB::table('productables')
                ->where('productable_type', 'App\\Models\\Material')
                ->selectRaw("product_id, productable_id, quantity, coalesce(prompt, 'ask'), position, created_at, updated_at")
                ->orderBy('id'),
        );

        DB::table('productables')->where('productable_type', 'App\\Models\\Material')->delete();

        Schema::table('productables', function (Blueprint $table) {
            $table->dropColumn(['prompt', 'position']);
            $table->unsignedInteger('quantity')->default(1)->change();
        });
    }
};
