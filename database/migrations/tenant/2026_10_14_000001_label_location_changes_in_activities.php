<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Werkbonnen, machines en afspraken legden een gewijzigde locatie vast met het
 * id als leesbare waarde, zodat de tijdlijn "Locatie gewijzigd van '-' naar '35'"
 * toonde. De modellen noemen de locatie nu bij haar titel; deze migratie zet de
 * bestaande regels recht. Een locatie die niet meer bestaat houdt haar id, want
 * er is niets anders meer om te tonen.
 */
return new class extends Migration
{
    private array $subject_types = [
        'App\\Models\\ServiceOrder',
        'App\\Models\\Asset',
        'App\\Models\\Event',
    ];

    public function up(): void
    {
        $changes = DB::table('activity_changes')
            ->join('activities', 'activities.id', '=', 'activity_changes.activity_id')
            ->where('activity_changes.field', 'location_id')
            ->whereIn('activities.subject_type', $this->subject_types)
            ->get([
                'activity_changes.id',
                'activity_changes.activity_id',
                'activity_changes.label',
                'activity_changes.old_value',
                'activity_changes.new_value',
                'activity_changes.old_label',
                'activity_changes.new_label',
            ]);

        $titles = DB::table('locations')
            ->whereIn('id', $changes->pluck('old_value')->merge($changes->pluck('new_value'))->filter()->unique())
            ->pluck('title', 'id');

        foreach ($changes as $change) {
            $old_label = $this->relabel($change->old_value, $change->old_label, $titles);
            $new_label = $this->relabel($change->new_value, $change->new_label, $titles);

            if ($old_label === $change->old_label && $new_label === $change->new_label) {
                continue;
            }

            DB::table('activity_changes')->where('id', $change->id)->update([
                'old_label' => $old_label,
                'new_label' => $new_label,
            ]);

            $description = DB::table('activities')->where('id', $change->activity_id)->value('description');

            DB::table('activities')->where('id', $change->activity_id)->update([
                'description' => str_replace(
                    $this->sentence($change->label, $change->old_label, $change->new_label),
                    $this->sentence($change->label, $old_label, $new_label),
                    $description,
                ),
            ]);
        }
    }

    public function down(): void {}

    private function relabel(?string $value, ?string $label, $titles): ?string
    {
        if ($value === null || $label !== $value || !isset($titles[$value])) {
            return $label;
        }

        return $titles[$value];
    }

    private function sentence(?string $label, ?string $old_label, ?string $new_label): string
    {
        return $label . ' gewijzigd van \'' . ($old_label ?? '-') . '\' naar \'' . ($new_label ?? '-') . '\'';
    }
};
