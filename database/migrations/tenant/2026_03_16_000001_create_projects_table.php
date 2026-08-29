<?php

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->foreignIdFor(Customer::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'project_manager_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('Niet gestart');
            $table->timestamps();
        });

        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('projected_date')->nullable();
            $table->date('actual_date')->nullable();
            $table->foreignIdFor(User::class, 'assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        $rows = [];
        $resources = [
            'project' => 'Project',
            'projectmilestone' => 'Projectmijlpaal',
        ];
        $actions = [
            'read' => 'zien',
            'create' => 'aanmaken',
            'update' => 'bijwerken',
            'delete' => 'verwijderen',
        ];

        foreach ($resources as $key => $label_base) {
            foreach ($actions as $action => $verb) {
                $rows[] = [
                    'name' => "{$key}.{$action}",
                    'label' => "{$label_base} {$verb}",
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('permissions')->upsert($rows, ['name'], ['label', 'updated_at']);
    }

    public function down(): void
    {
        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('projects');

        DB::table('permissions')->where('name', 'like', 'project.%')->delete();
        DB::table('permissions')->where('name', 'like', 'projectmilestone.%')->delete();
    }
};
