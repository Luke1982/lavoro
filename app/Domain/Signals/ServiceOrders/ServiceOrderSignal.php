<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\Signal;
use App\Models\ServiceOrder;

/**
 * Something happened to a werkbon. Listeners type-hint this to react to every
 * werkbon fact, or a concrete class to react to one.
 */
interface ServiceOrderSignal extends Signal
{
    public function serviceOrder(): ServiceOrder;
}
