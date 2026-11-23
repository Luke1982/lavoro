<?php

namespace Tests\Concerns;

use App\Domain\Integrations\Area;
use App\Domain\Quotes\QuoteOrigins;
use App\Domain\Signals\Attachments\DocumentAttached;
use App\Domain\Signals\Attachments\DocumentChanged;
use App\Domain\Signals\Attachments\DocumentRemoved;
use App\Domain\Signals\Attachments\ImageAnnotated;
use App\Domain\Signals\Attachments\ImageAttached;
use App\Domain\Signals\Attachments\ImageMoved;
use App\Domain\Signals\Attachments\ImageRemoved;
use App\Domain\Signals\Attachments\ImageRenamed;
use App\Domain\Signals\Attachments\ImageReplaced;
use App\Domain\Signals\Attachments\ImageShareChanged;
use App\Domain\Signals\Attachments\MainImageChanged;
use App\Domain\Signals\Attachments\RemarkAdded;
use App\Domain\Signals\Attachments\RemarkRemoved;
use App\Domain\Signals\BaseSignal;
use App\Domain\Signals\CustomFields\CustomFieldValuesChanged;
use App\Domain\Signals\Integrations\RecordGoneFromAdministration;
use App\Domain\Signals\Integrations\RecordLinkedToAdministration;
use App\Domain\Signals\ModelChanged;
use App\Domain\Signals\Quotes\QuoteMadeFrom;
use App\Domain\Signals\Suppliers\SupplierLinkChanged;
use App\Domain\Signals\Suppliers\SupplierLinked;
use App\Domain\Signals\Suppliers\SupplierUnlinked;
use App\Models\CalendarGrant;
use App\Models\Document;
use App\Models\Image;
use App\Models\Material;
use App\Models\Product;
use App\Models\Project;
use App\Models\Remark;
use App\Models\Traits\HasCustomFields;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The whole signal catalogue, found on disk, and a way to build every one of
 * them with empty models and neutral values: enough to inspect a signal's shape
 * without touching the database.
 *
 * Nothing is skipped. A signal that cannot be built fails the guard that asked
 * for it, and a signal that takes any record is built once for every record it
 * can be about, so it is judged against each of them instead of against none.
 */
trait BuildsEverySignal
{
    use FindsModelsWithHistory;

    /** @return array<int, class-string<BaseSignal>> */
    protected function signalClasses(): array
    {
        $base = app_path('Domain/Signals');
        $classes = [];

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

            if (!$reflection->isAbstract() && $reflection->isSubclassOf(BaseSignal::class)) {
                $classes[] = $class;
            }
        }

        $this->assertNotEmpty($classes, 'no signal was found, so this guard proves nothing');

        return $classes;
    }

    /**
     * Every signal, built for every record it can be about, keyed by a name that
     * says which: "ImageAttached (Ticket)". Fails when one cannot be built.
     *
     * @return array<string, BaseSignal>
     */
    protected function everySignal(): array
    {
        $signals = [];
        $unbuilt = [];

        foreach ($this->signalClasses() as $class) {
            foreach ($this->recordsAbout($class) as $record_class) {
                $name = class_basename($class) . ($record_class === null ? '' : ' (' . class_basename($record_class) . ')');

                try {
                    $signals[$name] = $this->instantiate($class, $record_class);
                } catch (\Throwable $e) {
                    $unbuilt[] = $name . ': ' . $e->getMessage();
                }
            }
        }

        $this->assertSame([], $unbuilt, "\nThese signals cannot be built to be checked. Teach BuildsEverySignal "
            . "to build them rather than letting them go unchecked:\n" . implode("\n", $unbuilt) . "\n");

        return $signals;
    }

    /**
     * The records a signal taking any record can be about, as the code that
     * raises it decides. A signal that takes any record and is not listed here
     * fails: unlisted, it would be checked against nothing.
     *
     * @return array<int, class-string<Model>|null>
     */
    private function recordsAbout(string $class): array
    {
        if (!$this->takesAnyRecord($class)) {
            return [null];
        }

        $records = match ($class) {
            ImageAttached::class, ImageAnnotated::class, ImageReplaced::class, ImageRenamed::class,
            ImageRemoved::class, ImageMoved::class, MainImageChanged::class => Image::ATTACHABLE_TO,
            ImageShareChanged::class => [Project::class],
            DocumentAttached::class, DocumentChanged::class, DocumentRemoved::class => Document::ATTACHABLE_TO,
            RemarkAdded::class, RemarkRemoved::class => Remark::ATTACHABLE_TO,
            SupplierLinked::class, SupplierUnlinked::class, SupplierLinkChanged::class => [Product::class, Material::class],
            RecordLinkedToAdministration::class, RecordGoneFromAdministration::class => array_map(fn (Area $area) => $area->modelClass(), Area::cases()),
            QuoteMadeFrom::class => array_values(QuoteOrigins::KINDS),
            CustomFieldValuesChanged::class => $this->modelsUsing(HasCustomFields::class),
            ModelChanged::class => $this->modelsWithHistory(),
            default => [],
        };

        if ($records === []) {
            $this->fail(class_basename($class) . ' takes any record, but BuildsEverySignal does not know which records it can be about. '
                . 'List them in recordsAbout(), so it is checked against each.');
        }

        return array_values(array_unique($records));
    }

    private function takesAnyRecord(string $class): bool
    {
        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && $type->getName() === Model::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds the signal, with an empty $record_class wherever it takes any record.
     *
     * @param  class-string<Model>|null  $record_class
     */
    protected function instantiate(string $class, ?string $record_class = null): BaseSignal
    {
        return new $class(...$this->arguments($class, $record_class));
    }

    /** @return array<int, mixed> */
    private function arguments(string $class, ?string $record_class): array
    {
        $arguments = [];

        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isOptional()) {
                break;
            }

            $arguments[] = $this->valueFor($parameter, $class, $record_class);
        }

        return $arguments;
    }

    /** Unsaved, but with an id, as every record a signal is raised about has one. */
    private function emptyModel(string $name): Model
    {
        $model = new $name;

        return $model->setAttribute($model->getKeyName(), 1);
    }

    /**
     * A model that a signal reads further records through while it is made,
     * built with those records in place; any other model is built empty.
     */
    private function modelWithItsRecords(string $name): ?Model
    {
        return match ($name) {
            CalendarGrant::class => (new CalendarGrant)->forceFill([
                'owner_user_id' => User::factory()->create()->id,
                'viewer_user_id' => User::factory()->create()->id,
            ]),
            default => null,
        };
    }

    private function valueFor(\ReflectionParameter $parameter, string $class, ?string $record_class): mixed
    {
        $type = $parameter->getType();
        $types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
        $named = array_values(array_filter($types, fn ($t) => $t instanceof \ReflectionNamedType && $t->getName() !== 'null'));

        if ($named === []) {
            throw new \LogicException('$' . $parameter->getName() . ' of ' . class_basename($class) . ' has no type to build from');
        }

        $name = $named[0]->getName();

        return match (true) {
            $name === Model::class => $this->emptyModel($record_class),
            is_subclass_of($name, Model::class) => $this->modelWithItsRecords($name) ?? $this->emptyModel($name),
            $name === 'string' => 'x',
            $name === 'int' => 1,
            $name === 'float' => 1.0,
            $name === 'bool' => true,
            $name === 'array' => [],
            $name === Collection::class => collect(),
            enum_exists($name) => $name::cases()[0],
            $name === \DateTimeInterface::class, $name === \DateTimeImmutable::class => new \DateTimeImmutable,
            class_exists($name) => new $name(...$this->arguments($name, $record_class)),
            default => throw new \LogicException('$' . $parameter->getName() . ' of ' . class_basename($class) . ' is a ' . $name . ', which cannot be built'),
        };
    }
}
