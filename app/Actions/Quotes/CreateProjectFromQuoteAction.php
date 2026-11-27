<?php

namespace App\Actions\Quotes;

use App\Actions\Projects\CreateProject;
use App\Domain\Signals\Quotes\QuoteConvertedToProject;
use App\Domain\Signals\Signals;
use App\Enums\QuoteStatuses;
use App\Exceptions\Refusal;
use App\Models\Project;
use App\Models\Quote;
use Illuminate\Support\Facades\DB;

/**
 * Starts a project from an accepted quote, for work too big for one werkbon.
 * The project is for the quote's customer, at its location and with its
 * contact person; the quote, and the werkbon already made from it, then belong
 * to the project. A werkbon made from the quote later starts in it.
 *
 * The quote gets its project without a "project changed" of its own, which
 * QuoteConvertedToProject already tells; the werkbon's does go on its timeline.
 */
class CreateProjectFromQuoteAction
{
    public function __construct(private readonly CreateProject $create) {}

    /** @param array<string, mixed> $data the validated project fields */
    public function execute(Quote $quote, array $data): Project
    {
        if ($quote->statusEnum() !== QuoteStatuses::geaccepteerd) {
            throw new Refusal('Alleen van een geaccepteerde offerte wordt een project gemaakt.');
        }

        if ($quote->project_id !== null) {
            throw new Refusal('Offerte ' . $quote->number . ' hoort al bij een project.');
        }

        return DB::transaction(function () use ($quote, $data) {
            $project = $this->create->execute([
                ...$data,
                'customer_id' => $quote->customer_id,
                'location_id' => $quote->location_id,
                'contact_id' => $quote->contact_id,
            ]);

            $quote->forceFill(['project_id' => $project->id])->saveQuietly();

            if ($quote->serviceOrder !== null && $quote->serviceOrder->project_id === null) {
                $quote->serviceOrder->update(['project_id' => $project->id]);
            }

            Signals::dispatch(new QuoteConvertedToProject($quote, $project));

            return $project;
        });
    }
}
