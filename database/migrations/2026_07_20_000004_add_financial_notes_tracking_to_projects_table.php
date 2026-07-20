<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('financial_notes_updated_at')->nullable()->after('financial_notes');
            $table->foreignIdFor(User::class, 'financial_notes_updated_by')
                ->nullable()
                ->after('financial_notes_updated_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('financial_notes_updated_by');
            $table->dropColumn('financial_notes_updated_at');
        });
    }
};
