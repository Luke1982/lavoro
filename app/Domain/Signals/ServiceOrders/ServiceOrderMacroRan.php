<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * A macro was run on a werkbon, with what was answered when it ran. The tasks and
 * keuringen it added have their own lines on the timeline; remarks and documents
 * have none anywhere in the application, so this line is where they show.
 */
class ServiceOrderMacroRan extends BaseSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public string $macro_title,
        /** @var array<int, string> "Hoeveel airco's? → 4", one per question on the path. */
        public array $answered = [],
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.macro_ran';
    }

    public static function label(): string
    {
        return 'Macro uitgevoerd';
    }

    public function activityCategory(): string
    {
        return 'other';
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityContext(): array
    {
        return array_values(array_filter([$this->service_order->customer]));
    }

    public function activityDescription(): ?string
    {
        return 'Macro "' . $this->macro_title . '" uitgevoerd'
            . ($this->answered === [] ? '' : ': ' . implode('; ', $this->answered));
    }
}
