<?php

namespace App\Domain\Assistant\Evals;

use App\Domain\Assistant\Contracts\Attachment;
use App\Models\Customer;
use App\Models\EventType;
use App\Models\PriceFormula;
use App\Models\ProductType;
use App\Models\User;
use App\Models\UserPlanGroup;
use InvalidArgumentException;

/**
 * One situation the assistant has to get right, from the first message to the
 * last click, and how to tell afterwards whether it did.
 *
 * The run happens against a real customer database inside a transaction that is
 * rolled back, so confirmations are actually carried out and the checks, which
 * run before the rollback, can read what they made.
 */
abstract class EvalScenario
{
    private const NAMED = [
        'customers' => Customer::class,
        'users' => User::class,
        'plan_groups' => UserPlanGroup::class,
        'formulas' => PriceFormula::class,
        'event_types' => EventType::class,
        'product_types' => ProductType::class,
    ];

    /** Unique and stable, e.g. offerte.hotel-multisplit — results are compared by it across runs. */
    abstract public function name(): string;

    /** offerte, planning, prijzen, ... */
    abstract public function suite(): string;

    abstract public function opening(): string;

    /**
     * What the simulated user knows and wants, in Dutch. It answers questions and
     * picks options from this and nothing else.
     */
    abstract public function persona(): string;

    /** @return array<int, Check> */
    abstract public function checks(EvalTranscript $transcript): array;

    /**
     * What goes along with the opening question, described rather than made:
     * --list and the provider check read the names and types, and only a run
     * prints the files.
     *
     * @return array<int, EvalFile>
     */
    public function files(): array
    {
        return [];
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return array_map(fn (EvalFile $file) => $file->attachment(), $this->files());
    }

    /** Who asks: one of EvalUsers::ROLES. */
    public function role(): string
    {
        return 'planner';
    }

    public function user(): ?User
    {
        return EvalUsers::forRole($this->role());
    }

    /**
     * The records, by name, that the conversation and the checks count on. In a
     * customer without them the scenario is skipped rather than passed: asked to
     * apply a formula to a customer that is not there, "nothing to apply" is
     * the right answer and would have counted as a pass.
     *
     * @return array<string, array<int, string>> Keyed customers, users, plan_groups, formulas, event_types or product_types.
     */
    public function needs(): array
    {
        return [];
    }

    /** Why this scenario cannot run in this customer's data, or null when it can. */
    public function unavailable(): ?string
    {
        if ($this->user() === null) {
            return 'geen gebruiker met de rol ' . $this->role();
        }

        $absent = [];

        foreach ($this->needs() as $kind => $names) {
            $model = self::NAMED[$kind] ?? throw new InvalidArgumentException('Onbekend soort gegeven "' . $kind . '" in ' . $this->name() . '.');
            $found = $model::query()->whereIn('name', $names)->pluck('name')->all();
            array_push($absent, ...array_diff($names, $found));
        }

        return $absent === [] ? null : 'ontbreekt hier: ' . implode(', ', $absent);
    }

    /** How many times the simulated user may answer, choose or click before the run is cut off. */
    public function maxMoves(): int
    {
        return 10;
    }

    /**
     * Things only a reader can judge, added to the general criteria in EvalJudge
     * that every conversation is held to.
     *
     * @return array<int, string>
     */
    public function rubric(): array
    {
        return [];
    }

    /** Runs first, inside the transaction that is rolled back: puts in place what the conversation has to find. */
    public function prepare(): void {}
}
