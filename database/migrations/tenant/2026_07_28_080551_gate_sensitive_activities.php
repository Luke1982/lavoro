<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The activity trail recorded sensitive values in full and showed them to anyone
 * who could open the record. Entries now carry the permission needed to read
 * them, and existing rows are gated by the field they reported.
 */
return new class extends Migration
{
    private array $permissions = [
        ['name' => 'maintenancecontract.see_financials', 'label' => 'Contract financiele gegevens zien'],
        ['name' => 'material.see_financials', 'label' => 'Materiaal financiele gegevens zien'],
        ['name' => 'customer.see_sensitive', 'label' => 'Klant gevoelige gegevens zien'],
    ];

    /** Field on a subject -> permission required to read an entry reporting it. */
    private array $gated_fields = [
        'financial_comments' => 'serviceorder.see_financials',
        'price' => 'maintenancecontract.see_financials',
        'price_interval' => 'maintenancecontract.see_financials',
        'price_interval_days' => 'maintenancecontract.see_financials',
        'cost_price' => 'material.see_financials',
        'iban' => 'customer.see_sensitive',
        'vat_number' => 'customer.see_sensitive',
    ];

    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('required_permission')->nullable()->index()->after('event_key');
        });

        foreach ($this->permissions as $permission) {
            if (!Permission::where('name', $permission['name'])->exists()) {
                Permission::create($permission);
            }
        }

        $this->backfill();
    }

    /**
     * Existing entries name the field they reported in activity_changes, which is
     * enough to gate them. Material prices are split: a material's own price is
     * what customers are quoted, its cost price is not.
     */
    private function backfill(): void
    {
        foreach ($this->gated_fields as $field => $permission) {
            $query = DB::table('activities')
                ->whereNull('required_permission')
                ->whereIn('id', DB::table('activity_changes')->where('field', $field)->select('activity_id'));

            if ($permission === 'maintenancecontract.see_financials') {
                $query->where('subject_type', 'App\\Models\\MaintenanceContract');
            }

            if ($permission === 'material.see_financials') {
                $query->where('subject_type', 'App\\Models\\Material');
            }

            if ($permission === 'customer.see_sensitive') {
                $query->where('subject_type', 'App\\Models\\Customer');
            }

            $query->update(['required_permission' => $permission]);
        }

        /**
         * Older entries predate both the change rows and the subject columns, so
         * neither is available to match on. They are reachable only through the
         * activityables pivot, and identifiable only by their sentence.
         */
        DB::table('activities')
            ->whereNull('required_permission')
            ->whereIn('id', function ($q) {
                $q->select('activity_id')
                    ->from('activityables')
                    ->where('activityable_type', 'App\\Models\\ServiceOrder');
            })
            ->where(function ($q) {
                $q->where('description', 'like', '%inancial comments%')
                    ->orWhere('description', 'like', '%inanciële opmerkingen%');
            })
            ->update(['required_permission' => 'serviceorder.see_financials']);
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('required_permission');
        });

        Permission::whereIn('name', array_column($this->permissions, 'name'))->delete();
    }
};
