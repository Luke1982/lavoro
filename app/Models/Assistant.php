<?php

namespace App\Models;

/**
 * Not a table — a name for the assistant so it can carry a policy.
 *
 * Laravel resolves policies by class, and "may this person use the assistant" is
 * a real authorisation question that belongs with the others rather than as a
 * permission string checked by hand in a controller.
 */
class Assistant
{
    //
}
