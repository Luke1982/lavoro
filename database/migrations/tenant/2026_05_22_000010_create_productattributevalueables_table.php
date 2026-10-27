<?php

use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productattributevalueables', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ProductAttributeValue::class)->constrained()->cascadeOnDelete();
            $table->string('productattributevalueable_type');
            $table->unsignedBigInteger('productattributevalueable_id');
            $table->index(
                ['productattributevalueable_type', 'productattributevalueable_id'],
                'pav_morph_index'
            );
            $table->foreignIdFor(ProductAttribute::class)->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['productattributevalueable_type', 'productattributevalueable_id', 'product_attribute_id'],
                'productattributevalueables_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productattributevalueables');
    }
};
