<?php

use App\Models\Asset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Asset::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->string('subject')->required();
            $table->text('description')->nullable();
            $table->enum('status', ['Open', 'In behandeling', 'Gesloten'])
                ->default('Open');
            $table->enum('priority', ['Laag', 'Normaal', 'Hoog'])
                ->default('Normaal');
            $table->date('closed_on')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
