<?php

use App\Domain\Signals\BaseSignal;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MajorLabel's own accounts are hidden from the customer everywhere, and the trail
 * now credits their work to "MajorLabel" instead of naming the person. Entries
 * written before that still carry the name, and the new timelines and the
 * assistant read them. This gives them the same credit. Who it was stays known
 * through user_id, so nothing is lost that the trail ever needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('activities')
            ->whereIn('user_id', DB::table('roleables')
                ->join('roles', 'roles.id', '=', 'roleables.role_id')
                ->where('roles.name', Role::SUPERADMIN)
                ->where('roleables.roleable_type', User::class)
                ->select('roleables.roleable_id'))
            ->update(['actor_name' => BaseSignal::MAJORLABEL]);
    }

    public function down(): void {}
};
