<?php

use App\Models\Activity;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activityables', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Activity::class)->constrained();
            $table->morphs('activityable');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activityables');
    }
};
