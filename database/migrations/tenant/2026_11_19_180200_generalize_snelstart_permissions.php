<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The SnelStart rights become rights over every linked system. They are renamed
 * in place, so each role keeps what it had: whoever could send a werkbon to
 * SnelStart can now send one to the administration, whatever it is.
 *
 * Fetching customers and fetching articles were two rights; they are one now,
 * held by every role that had either.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('snelstart.send_serviceorder', 'integration.send_serviceorder', 'Werkbon naar de administratie versturen');
        $this->rename('snelstart.get_customers', 'integration.pull', 'Klanten en materialen ophalen uit gekoppelde systemen');

        $articles = Permission::where('name', 'snelstart.get_articles')->first();
        $pull = Permission::where('name', 'integration.pull')->first();

        if ($articles && $pull) {
            $holders = DB::table('permissionables')->where('permission_id', $articles->id)->get();

            foreach ($holders as $holder) {
                $already = DB::table('permissionables')
                    ->where('permission_id', $pull->id)
                    ->where('permissionable_type', $holder->permissionable_type)
                    ->where('permissionable_id', $holder->permissionable_id)
                    ->exists();

                if (!$already) {
                    DB::table('permissionables')->where('id', $holder->id)->update(['permission_id' => $pull->id]);
                }
            }
        }

        if ($articles) {
            DB::table('permissionables')->where('permission_id', $articles->id)->delete();
            $articles->delete();
        }

        if (!$pull) {
            Permission::create(['name' => 'integration.pull', 'label' => 'Klanten en materialen ophalen uit gekoppelde systemen']);
        }

        if (!Permission::where('name', 'integration.send_serviceorder')->exists()) {
            Permission::create(['name' => 'integration.send_serviceorder', 'label' => 'Werkbon naar de administratie versturen']);
        }

        if (!Permission::where('name', 'integration.manage')->exists()) {
            Permission::create(['name' => 'integration.manage', 'label' => 'Koppelingen met andere systemen beheren']);
        }
    }

    public function down(): void
    {
        Permission::where('name', 'integration.manage')->delete();
        $this->rename('integration.send_serviceorder', 'snelstart.send_serviceorder', 'Stuur werkbon naar snelstart');
        $this->rename('integration.pull', 'snelstart.get_customers', 'Haal klanten op uit snelstart');

        if (!Permission::where('name', 'snelstart.get_articles')->exists()) {
            Permission::create(['name' => 'snelstart.get_articles', 'label' => 'Haal artikelen op uit snelstart']);
        }
    }

    private function rename(string $from, string $to, string $label): void
    {
        Permission::where('name', $from)->update(['name' => $to, 'label' => $label]);
    }
};
