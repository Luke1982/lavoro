<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quote kept to start the next one from, such as "airco, one room": its texts,
 * its terms and its lines, at the prices it was saved with. The content is a
 * copy and not a link, so a template outlives the quote it came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('content');
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_templates');
    }
};
