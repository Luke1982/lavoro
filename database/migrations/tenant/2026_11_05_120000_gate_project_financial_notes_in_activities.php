<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * De tijdlijn van een project legde de financiële notities bij elke wijziging in
 * hun geheel vast, voor en na, leesbaar voor iedereen die het project mocht zien
 * en voor de assistent. Het model legt ze niet meer vast; de regels die er al
 * staan zijn voortaan alleen zichtbaar voor wie de projectfinanciën beheert.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('activities')
            ->whereNull('required_permission')
            ->where('subject_type', 'App\\Models\\Project')
            ->whereIn('id', DB::table('activity_changes')->where('field', 'financial_notes')->select('activity_id'))
            ->update(['required_permission' => 'project.manage_financials']);
    }
};
