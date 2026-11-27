<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paying for the real hours and materials is called nacalculatie, not regie: on
 * every quote, and in the templates new quotes start from. A version that was
 * sent keeps the words it went out with.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('Regie', 'Nacalculatie');
    }

    public function down(): void
    {
        $this->rename('Nacalculatie', 'Regie');
    }

    private function rename(string $from, string $to): void
    {
        DB::table('quotes')->where('pricing_method', $from)->update(['pricing_method' => $to]);

        DB::table('quote_templates')->orderBy('id')->get(['id', 'content'])->each(function (object $template) use ($from, $to) {
            $content = json_decode($template->content, true);

            if (($content['quote']['pricing_method'] ?? null) !== $from) {
                return;
            }

            $content['quote']['pricing_method'] = $to;
            DB::table('quote_templates')->where('id', $template->id)->update(['content' => json_encode($content)]);
        });
    }
};
