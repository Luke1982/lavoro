<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectFinancialNotesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private string $migration = 'database/migrations/2026_07_20_000003_convert_project_financial_notes_to_snapshot_shape.php';

    private function project(): Project
    {
        return Project::create([
            'title' => 'Testproject',
            'customer_id' => Customer::factory()->create()->id,
            'project_manager_id' => User::factory()->create()->id,
            'status' => 'new',
        ]);
    }

    private function raw(int $project_id): ?array
    {
        $stored = DB::table('projects')->where('id', $project_id)->value('financial_notes');

        return $stored === null ? null : json_decode($stored, true);
    }

    private function storeRaw(int $project_id, ?string $json): void
    {
        DB::table('projects')->where('id', $project_id)->update(['financial_notes' => $json]);
    }

    private function runMigration(): object
    {
        return require base_path($this->migration);
    }

    public function test_a_legacy_bare_array_is_converted_to_the_snapshot_shape(): void
    {
        $project = $this->project();
        $this->storeRaw($project->id, json_encode([['Oud', 'formaat'], ['x', 1]]));

        $this->runMigration()->up();

        $this->assertSame([
            'data' => [['Oud', 'formaat'], ['x', 1]],
            'style' => [],
            'mergeCells' => [],
            'columns' => [],
        ], $this->raw($project->id));
    }

    public function test_rows_already_in_the_snapshot_shape_are_left_alone(): void
    {
        $project = $this->project();
        $snapshot = [
            'data' => [['a']],
            'style' => ['A1' => 'color: red;'],
            'mergeCells' => ['B1' => [2, 1]],
            'columns' => [['width' => 200]],
        ];
        $this->storeRaw($project->id, json_encode($snapshot));

        $this->runMigration()->up();

        $this->assertSame($snapshot, $this->raw($project->id));
    }

    public function test_null_rows_are_untouched(): void
    {
        $project = $this->project();
        $this->storeRaw($project->id, null);

        $this->runMigration()->up();

        $this->assertNull($this->raw($project->id));
    }

    public function test_conversion_does_not_bump_the_updated_at_timestamp(): void
    {
        $project = $this->project();
        $this->storeRaw($project->id, json_encode([['Oud', 'formaat']]));
        DB::table('projects')->where('id', $project->id)->update(['updated_at' => '2020-01-01 00:00:00']);

        $this->runMigration()->up();

        $this->assertSame(
            '2020-01-01 00:00:00',
            (string) DB::table('projects')->where('id', $project->id)->value('updated_at')
        );
    }

    public function test_down_restores_the_bare_array(): void
    {
        $project = $this->project();
        $this->storeRaw($project->id, json_encode([['Oud', 'formaat']]));

        $migration = $this->runMigration();
        $migration->up();
        $migration->down();

        $this->assertSame([['Oud', 'formaat']], $this->raw($project->id));
    }
}
