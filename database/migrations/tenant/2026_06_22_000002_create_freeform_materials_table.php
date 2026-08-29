<?php

use App\Models\ServiceOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('freeform_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ServiceOrder::class)->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 10, 2);
            $table->string('description');
            $table->boolean('unforseen')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freeform_materials');
    }
};
