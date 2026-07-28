<?php

namespace Tests\Feature\Signals;

use App\Domain\Signals\BaseSignal;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The trail records sensitive values in full so the people entitled to them keep
 * a real audit trail. These cover that everyone else never sees them.
 */
class SensitiveActivityGatingTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
        ]);
    }

    public function test_a_financial_change_is_gated_behind_the_financial_permission(): void
    {
        $order = $this->order();

        $order->update(['financial_comments' => 'Offerte 54641, marge 22%']);

        $activity = Activity::where('subject_id', $order->id)
            ->whereNotNull('required_permission')->sole();

        $this->assertSame('serviceorder.see_financials', $activity->required_permission);
        $this->assertStringContainsString('54641', $activity->description);
    }

    public function test_someone_without_the_permission_cannot_see_it(): void
    {
        $order = $this->order();
        $order->update(['financial_comments' => 'Marge 22%']);

        $outsider = $this->userWith('serviceorder.read');

        $visible = Activity::visibleTo($outsider)->get();

        $this->assertFalse(
            $visible->contains(fn (Activity $a) => str_contains((string) $a->description, 'Marge')),
            'a financial value leaked to someone without the permission'
        );
    }

    public function test_someone_with_the_permission_still_sees_the_full_history(): void
    {
        $order = $this->order();
        $order->update(['financial_comments' => 'Marge 22%']);

        $insider = $this->userWith('serviceorder.see_financials');

        $visible = Activity::visibleTo($insider)->get();

        $this->assertTrue(
            $visible->contains(fn (Activity $a) => str_contains((string) $a->description, 'Marge')),
            'the audit trail was hidden from someone entitled to it'
        );
    }

    public function test_a_mixed_save_splits_into_an_open_entry_and_a_gated_one(): void
    {
        $order = $this->order();

        $order->update([
            'description' => 'Nieuwe omschrijving',
            'financial_comments' => 'Marge 22%',
        ]);

        $open = Activity::where('subject_id', $order->id)
            ->where('event_key', 'serviceorder.updated')
            ->whereNull('required_permission')->sole();

        $gated = Activity::where('subject_id', $order->id)
            ->where('event_key', 'serviceorder.updated')
            ->whereNotNull('required_permission')->sole();

        $this->assertStringContainsString('Nieuwe omschrijving', $open->description);
        $this->assertStringNotContainsString('Marge', $open->description);
        $this->assertStringContainsString('Marge', $gated->description);
    }

    public function test_ordinary_history_is_never_hidden_from_anyone(): void
    {
        $order = $this->order();
        $order->update(['description' => 'Gewoon een wijziging']);

        $nobody = $this->userWith('serviceorder.read');

        $this->assertTrue(
            Activity::visibleTo($nobody)->get()
                ->contains(fn (Activity $a) => str_contains((string) $a->description, 'Gewoon een wijziging'))
        );
    }

    /**
     * A named signal can report a field the model gates, which the automatic
     * grouping never sees. It has to gate itself from the same declaration, or
     * the value goes out through a side door.
     */
    public function test_every_named_signal_reporting_a_gated_field_declares_the_permission(): void
    {
        $leaks = [];
        $base = app_path('Domain/Signals');

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace([$base . DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
            $class = 'App\\Domain\\Signals\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || !$reflection->isSubclassOf(BaseSignal::class)) {
                continue;
            }

            $model = $this->subjectModelFor($reflection);

            if ($model === null || !method_exists($model, 'permissionForField')) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            preg_match_all("/'field' => '([a-z_]+)'/", $source, $matches);

            foreach ($matches[1] as $field) {
                if ((new $model)->permissionForField($field) && !str_contains($source, 'requiredPermission')) {
                    $leaks[] = class_basename($class) . " reports gated field '{$field}' without declaring a permission";
                }
            }
        }

        $this->assertSame([], $leaks, "\n" . implode("\n", $leaks) . "\n");
    }

    private function subjectModelFor(\ReflectionClass $reflection): ?string
    {
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType
                && !$type->isBuiltin()
                && is_subclass_of($type->getName(), Model::class)) {
                return $type->getName();
            }
        }

        return null;
    }

    public function test_an_admin_sees_everything(): void
    {
        $order = $this->order();
        $order->update(['financial_comments' => 'Marge 22%']);

        $this->assertTrue(
            Activity::visibleTo($this->admin())->get()
                ->contains(fn (Activity $a) => str_contains((string) $a->description, 'Marge'))
        );
    }
}
