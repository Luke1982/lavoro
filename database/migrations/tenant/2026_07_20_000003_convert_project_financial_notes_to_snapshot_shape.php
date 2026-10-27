<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('projects')
            ->whereNotNull('financial_notes')
            ->orderBy('id')
            ->select(['id', 'financial_notes'])
            ->chunk(200, function ($projects) {
                foreach ($projects as $project) {
                    $financial_notes = json_decode($project->financial_notes, true);

                    if (!is_array($financial_notes) || $financial_notes === [] || !array_is_list($financial_notes)) {
                        continue;
                    }

                    DB::table('projects')->where('id', $project->id)->update([
                        'financial_notes' => json_encode([
                            'data' => $financial_notes,
                            'style' => (object) [],
                            'mergeCells' => (object) [],
                            'columns' => [],
                        ]),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('projects')
            ->whereNotNull('financial_notes')
            ->orderBy('id')
            ->select(['id', 'financial_notes'])
            ->chunk(200, function ($projects) {
                foreach ($projects as $project) {
                    $financial_notes = json_decode($project->financial_notes, true);

                    if (!is_array($financial_notes) || !array_key_exists('data', $financial_notes)) {
                        continue;
                    }

                    DB::table('projects')->where('id', $project->id)->update([
                        'financial_notes' => json_encode($financial_notes['data']),
                    ]);
                }
            });
    }
};
