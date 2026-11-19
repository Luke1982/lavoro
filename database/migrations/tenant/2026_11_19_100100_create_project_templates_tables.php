<?php

use App\Models\ProjectTemplate;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A projectsjabloon is the set of milestones a kind of job always runs through,
 * each placed a number of days after the project starts. Whoever may make projects
 * may manage them from the start: they are the people who know those steps.
 */
return new class extends Migration
{
    private const PERMISSION = 'projecttemplate.manage';

    public function up(): void
    {
        Schema::create('project_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('project_template_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ProjectTemplate::class)->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('offset_days')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('permissions')->upsert([[
            'name' => self::PERMISSION,
            'label' => 'Projectsjablonen beheren',
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['name'], ['label', 'updated_at']);

        $permission_id = DB::table('permissions')->where('name', self::PERMISSION)->value('id');
        $creator_permission_id = DB::table('permissions')->where('name', 'project.create')->value('id');

        if (!$permission_id || !$creator_permission_id) {
            return;
        }

        DB::table('permissionables')
            ->where('permission_id', $creator_permission_id)
            ->where('permissionable_type', Role::class)
            ->pluck('permissionable_id')
            ->each(fn ($role_id) => DB::table('permissionables')->insertOrIgnore([
                'permission_id' => $permission_id,
                'permissionable_type' => Role::class,
                'permissionable_id' => $role_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', self::PERMISSION)->delete();
        Schema::dropIfExists('project_template_milestones');
        Schema::dropIfExists('project_templates');
    }
};
