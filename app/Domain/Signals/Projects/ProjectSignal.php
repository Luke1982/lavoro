<?php

namespace App\Domain\Signals\Projects;

use App\Domain\Signals\Signal;
use App\Models\Project;

/**
 * Something happened to a project. Listeners type-hint this to react to every
 * project fact, or a concrete class to react to one.
 */
interface ProjectSignal extends Signal
{
    public function project(): Project;
}
