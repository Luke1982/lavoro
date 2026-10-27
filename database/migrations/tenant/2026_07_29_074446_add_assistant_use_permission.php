<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The assistant is unreleased and being tried by a handful of people, so this
     * permission is granted by hand and held by nobody to begin with.
     *
     * Unlike every other permission here it is not implied by being an admin
     * either — User::hasExplicitPermission is what guards it. That is deliberate:
     * "everyone with the keys to the building" is a far wider group than "the
     * people testing this", and an admin who was not added should not find the
     * assistant waiting for them.
     */
    public function up(): void
    {
        $now = Carbon::now();

        DB::table('permissions')->insert([
            [
                'name' => 'assistant.use',
                'label' => 'AI-assistent gebruiken',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'assistant.use')->delete();
    }
};
