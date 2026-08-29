<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $renames = [
        'events.see_beyond_current_week' => ['name' => 'event.see_beyond_current_week', 'label' => 'Mag afspraken zien voorbij de huidige week'],
        'events.provide_feedback' => ['name' => 'event.provide_feedback', 'label' => 'Mag terugkoppeling geven op afspraken'],
        'events.release_times' => ['name' => 'event.release_times', 'label' => 'Mag geregistreerde tijden van een afspraak vrijgeven'],
    ];

    private array $label_fixes = [
        'event.export' => 'Mag de afsprakenplanning exporteren',
    ];

    private array $new_permissions = [
        ['name' => 'event.execute_others', 'label' => 'Mag tijden van afspraken van anderen invullen'],
    ];

    public function up(): void
    {
        foreach ($this->renames as $old_name => $data) {
            $permission = Permission::where('name', $old_name)->first();
            if ($permission) {
                $permission->update($data);
            } elseif (! Permission::where('name', $data['name'])->exists()) {
                Permission::create($data);
            }
        }

        foreach ($this->label_fixes as $name => $label) {
            Permission::where('name', $name)->update(['label' => $label]);
        }

        foreach ($this->new_permissions as $permission) {
            if (! Permission::where('name', $permission['name'])->exists()) {
                Permission::create($permission);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->renames as $old_name => $data) {
            Permission::where('name', $data['name'])->update(['name' => $old_name]);
        }

        foreach ($this->new_permissions as $permission) {
            Permission::where('name', $permission['name'])->delete();
        }
    }
};
