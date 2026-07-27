<?php

namespace Tests\Feature\Signals;

use App\Domain\Signals\BaseSignal;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * A field reported by a named signal must be excluded from the automatic audit
 * trail, or one change gets logged twice. Nothing enforces that pairing at
 * runtime, so it is enforced here instead of being remembered by hand.
 */
class NoDoubleLoggingTest extends TestCase
{
    public function test_no_field_is_reported_by_both_a_named_signal_and_the_automatic_trail(): void
    {
        $conflicts = [];

        foreach ($this->signalClasses() as $class) {
            $covered = $class::coveredFields();

            if ($covered === []) {
                continue;
            }

            $model = $this->subjectModelFor($class);

            if ($model === null) {
                continue;
            }

            $ignored = $this->activityIgnoreOf($model);

            foreach ($covered as $field) {
                if (!in_array($field, $ignored, true)) {
                    $conflicts[] = sprintf(
                        '%s reports "%s" but %s does not list it in $activity_ignore',
                        class_basename($class),
                        $field,
                        class_basename($model),
                    );
                }
            }
        }

        $this->assertSame([], $conflicts, "\n" . implode("\n", $conflicts) . "\n");
    }

    public function test_the_signal_catalogue_is_actually_discoverable(): void
    {
        $this->assertNotEmpty(
            $this->signalClasses(),
            'no signals were found, so the guard above proves nothing'
        );
    }

    /** @return array<int, class-string<BaseSignal>> */
    private function signalClasses(): array
    {
        $base = app_path('Domain/Signals');
        $classes = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace([$base . DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
            $class = 'App\\Domain\\Signals\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if (!$reflection->isAbstract() && $reflection->isSubclassOf(BaseSignal::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** The first Eloquent model in a signal's constructor is the record it is about. */
    private function subjectModelFor(string $signal_class): ?string
    {
        $constructor = (new \ReflectionClass($signal_class))->getConstructor();

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            if (is_subclass_of($type->getName(), Model::class)) {
                return $type->getName();
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function activityIgnoreOf(string $model_class): array
    {
        $property = 'activity_ignore';
        $reflection = new \ReflectionClass($model_class);

        if (!$reflection->hasProperty($property)) {
            return [];
        }

        return $reflection->getProperty($property)->getDefaultValue() ?? [];
    }
}
