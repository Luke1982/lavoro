<?php

use App\Models\DocumentCategory;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignIdFor(DocumentCategory::class)->nullable()->after('title')->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class)->nullable()->after('document_category_id')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('size')->nullable()->after('path');
        });

        DB::table('documents')->orderBy('id')->chunkById(200, function ($documents) {
            foreach ($documents as $document) {
                if (!Storage::disk('public')->exists($document->path)) {
                    continue;
                }

                DB::table('documents')
                    ->where('id', $document->id)
                    ->update(['size' => Storage::disk('public')->size($document->path)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(DocumentCategory::class);
            $table->dropConstrainedForeignIdFor(User::class);
            $table->dropColumn('size');
        });
    }
};
