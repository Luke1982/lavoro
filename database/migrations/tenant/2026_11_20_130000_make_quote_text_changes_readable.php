<?php

use App\Models\Quote;
use App\Support\RichText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Changes to a quote's opening and closing text, and to the paragraph under a
 * text line, were written to the timeline with their HTML. They now read as
 * plain text when they are written; this does the same for the ones already
 * there. Only the readable labels change, the stored values keep the markup.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('activity_changes')
            ->join('activities', 'activities.id', '=', 'activity_changes.activity_id')
            ->where('activities.subject_type', Quote::class)
            ->whereIn('activity_changes.field', ['intro_text', 'closing_text', 'details'])
            ->where(fn ($query) => $query->where('activity_changes.old_label', 'like', '%<%')
                ->orWhere('activity_changes.new_label', 'like', '%<%'))
            ->select('activity_changes.id', 'activity_changes.old_label', 'activity_changes.new_label')
            ->orderBy('activity_changes.id')
            ->get()
            ->each(fn ($change) => DB::table('activity_changes')->where('id', $change->id)->update([
                'old_label' => RichText::plain($change->old_label),
                'new_label' => RichText::plain($change->new_label),
            ]));
    }

    public function down(): void {}
};
