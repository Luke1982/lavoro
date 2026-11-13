<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named macros: a series of steps run on a werkbon in one go, stored as a tree
 * of steps that a person, the editor and the assistant all read the same way.
 */
return new class extends Migration
{
    private array $permission = [
        'name' => 'serviceordermacro.manage',
        'label' => "Macro's beheren",
    ];

    public function up(): void
    {
        Schema::create('service_order_macros', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('definition');
            $table->timestamps();
        });

        if (!Permission::where('name', $this->permission['name'])->exists()) {
            Permission::create($this->permission);
        }
    }

    public function down(): void
    {
        Permission::where('name', $this->permission['name'])->delete();
        Schema::dropIfExists('service_order_macros');
    }
};
