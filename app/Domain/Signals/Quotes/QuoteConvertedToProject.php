<?php

namespace App\Domain\Signals\Quotes;

use App\Domain\Signals\BaseSignal;
use App\Models\Project;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Model;

/**
 * A project was started from an accepted quote. On the quote, on the project
 * and on the customer, because on each of them someone will ask where the other
 * came from. Only numbers are in it, so whoever reads the project may read this.
 * The quote's project can also be picked by hand, which its own trail tells, so
 * this does not claim the field: the action links the quote without a trail.
 */
class QuoteConvertedToProject extends BaseSignal implements QuoteSignal
{
    public function __construct(public Quote $quote, public Project $project)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'quote.project_created';
    }

    public static function label(): string
    {
        return 'Project gemaakt van offerte';
    }

    public function quote(): Quote
    {
        return $this->quote;
    }

    public function subject(): Model
    {
        return $this->quote;
    }

    public function activityCategory(): string
    {
        return 'created';
    }

    public function activityDescription(): ?string
    {
        return 'Project ' . $this->project->number . ' gemaakt van offerte ' . $this->quote->label();
    }

    public function activityContext(): array
    {
        return array_values(array_filter([$this->project, $this->quote->customer]));
    }
}
