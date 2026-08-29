<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('status_code')->nullable()->after('priority');
            $table->foreignIdFor(User::class, 'closed_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->after('status_code');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by_id');
            $table->dropColumn('status_code');
        });
    }
};
