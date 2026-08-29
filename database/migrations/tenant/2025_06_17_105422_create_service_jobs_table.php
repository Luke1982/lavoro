<?php

use App\Models\Asset;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Enums\ServiceJobOutcomes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('service_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Asset::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(ServiceOrder::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->enum(
                'outcome',
                array_map(
                    fn($case) => $case->value,
                    ServiceJobOutcomes::cases()
                )
            );
            $table->integer('days_temporary_approval')->default(0);
            $table->string('description')->nullable();
            $table->date('completed_on')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_jobs');
    }
};
