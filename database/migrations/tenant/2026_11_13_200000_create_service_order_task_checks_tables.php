<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checks a mechanic ticks off on a werkbon task, and where they come from.
 *
 * A check lives in a library and is linked to a task template or a checklist; a
 * checklist is linked to a task template. Each link carries its own required flag
 * and order, so the same check or checklist can be required on one task and
 * optional on another. A werkbon task copies the outcome into rows of its own, so
 * a template edited later never changes a werkbon that is already running.
 *
 * Index and key names are spelled out: the generated ones pass MySQL's 64
 * characters on these table names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_order_task_checks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('service_order_task_checklists', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('service_order_task_checkables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_task_check_id')
                ->constrained('service_order_task_checks', 'id', 'sotc_check_fk')
                ->cascadeOnDelete();
            $table->morphs('checkable', 'sotc_checkable_index');
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['service_order_task_check_id', 'checkable_type', 'checkable_id'],
                'sotc_check_checkable_unique'
            );
        });

        Schema::create('service_order_task_checklistables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_task_checklist_id')
                ->constrained('service_order_task_checklists', 'id', 'sotcl_checklist_fk')
                ->cascadeOnDelete();
            $table->morphs('checklistable', 'sotcl_checklistable_index');
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['service_order_task_checklist_id', 'checklistable_type', 'checklistable_id'],
                'sotcl_list_checklistable_unique'
            );
        });

        Schema::create('service_order_task_instance_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_task_instance_id')
                ->constrained('service_order_task_instances', 'id', 'sotic_instance_fk')
                ->cascadeOnDelete();
            $table->foreignId('service_order_task_check_id')->nullable()
                ->constrained('service_order_task_checks', 'id', 'sotic_check_fk')
                ->nullOnDelete();
            $table->foreignId('service_order_task_checklist_id')->nullable()
                ->constrained('service_order_task_checklists', 'id', 'sotic_checklist_fk')
                ->nullOnDelete();
            $table->string('title');
            $table->string('checklist_title')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_extra')->default(false);
            $table->boolean('is_done')->default(false);
            $table->unsignedInteger('unit_index')->nullable();
            $table->foreignId('asset_id')->nullable()
                ->constrained('assets', 'id', 'sotic_asset_fk')
                ->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->dateTime('checked_at')->nullable();
            $table->foreignId('checked_by')->nullable()
                ->constrained('users', 'id', 'sotic_checked_by_fk')
                ->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('service_order_task_instances', function (Blueprint $table) {
            $table->boolean('checks_per_unit')->default(false)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('service_order_task_instances', function (Blueprint $table) {
            $table->dropColumn('checks_per_unit');
        });

        Schema::dropIfExists('service_order_task_instance_checks');
        Schema::dropIfExists('service_order_task_checklistables');
        Schema::dropIfExists('service_order_task_checkables');
        Schema::dropIfExists('service_order_task_checklists');
        Schema::dropIfExists('service_order_task_checks');
    }
};
