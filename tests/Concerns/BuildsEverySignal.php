<?php

namespace Tests\Concerns;

use App\Domain\Signals\BaseSignal;
use Illuminate\Database\Eloquent\Model;

/**
 * The whole signal catalogue, found on disk, and a way to build any of them with
 * empty models and neutral values: enough to inspect a signal's shape without
 * touching the database.
 */
trait BuildsEverySignal
{
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

        return $classes;
    }

    protected function instantiate(string $class): ?BaseSignal
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        $arguments = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof \ReflectionNamedType) {
                return null;
            }

            $name = $type->getName();

            $arguments[] = match (true) {
                is_subclass_of($name, Model::class) => new $name,
                $name === Model::class => new class extends Model {},
                $name === 'string' => 'x',
                $name === 'int' => 1,
                $name === 'float' => 1.0,
                $name === 'bool' => true,
                $name === 'array' => [],
                $name === \DateTimeInterface::class, $name === \DateTimeImmutable::class => new \DateTimeImmutable,
                default => null,
            };
        }

        try {
            return new $class(...$arguments);
        } catch (\Throwable) {
            return null;
        }
    }
}
