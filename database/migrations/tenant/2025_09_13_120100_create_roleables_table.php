<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('roleables', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Role::class)->constrained()->cascadeOnDelete();
            $table->morphs('roleable');
            $table->timestamps();

            $table->unique(['role_id', 'roleable_type', 'roleable_id'], 'roleables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roleables');
    }
};
