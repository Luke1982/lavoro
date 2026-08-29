<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standard_email_standard_attachment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('standard_email_id')->constrained()->cascadeOnDelete();
            $table->foreignId('standard_attachment_id')
                ->constrained(indexName: 'sesa_standard_attachment_id_foreign')
                ->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standard_email_standard_attachment');
    }
};
