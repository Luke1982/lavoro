<?php

namespace Tests\Feature\Tenancy;

use App\Support\ForgetsTenantState;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class TenantStateTest extends TestCase
{
    public function test_every_stateful_class_is_tagged(): void
    {
        $tagged = collect(app()->tagged(ForgetsTenantState::class))
            ->map(fn ($state) => $state::class);

        $implementors = collect(Finder::create()->files()->in(app_path())->name('*.php'))
            ->map(fn ($file) => 'App\\' . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
            ->filter(fn (string $class) => class_exists($class) && is_subclass_of($class, ForgetsTenantState::class));

        $this->assertEmpty(
            $implementors->diff($tagged)->all(),
            'Deze klassen implementeren ForgetsTenantState maar zijn niet getagd in AppServiceProvider.'
        );
    }
}
