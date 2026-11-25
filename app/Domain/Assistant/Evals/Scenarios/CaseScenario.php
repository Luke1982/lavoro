<?php

namespace App\Domain\Assistant\Evals\Scenarios;

use App\Domain\Assistant\Evals\Check;
use App\Domain\Assistant\Evals\EvalFile;
use App\Domain\Assistant\Evals\EvalScenario;
use App\Domain\Assistant\Evals\EvalTranscript;
use App\Models\User;
use Closure;

/**
 * A scenario written out in place: the question, what the person knows, what to
 * set up first and what to check afterwards. For the many small cases that do
 * not share enough to deserve a class of their own.
 */
class CaseScenario extends EvalScenario
{
    /** Whatever prepare() set up, for the checks to compare against. */
    private array $state = [];

    /**
     * @param  Closure(EvalTranscript, array, ?User): array<int, Check>  $checks  With what prepare() returned and who asked.
     * @param  array<int, EvalFile>  $files  Sent along with the opening question.
     * @param  Closure(): ?array|null  $prepare  Runs inside the rolled-back transaction; may return state for the checks.
     * @param  array<string, array<int, string>>  $needs  See EvalScenario::needs().
     * @param  Closure(): ?string|null  $unavailable  Anything else that has to be there first, said as why not.
     */
    public function __construct(
        private readonly string $name,
        private readonly string $suite,
        private readonly string $opening,
        private readonly string $persona,
        private readonly Closure $checks,
        private readonly string $role = 'planner',
        private readonly array $needs = [],
        private readonly ?Closure $prepare = null,
        private readonly array $rubric = [],
        private readonly array $files = [],
        private readonly ?Closure $unavailable = null,
    ) {}

    public function files(): array
    {
        return $this->files;
    }

    public function name(): string
    {
        return $this->suite . '.' . $this->name;
    }

    public function suite(): string
    {
        return $this->suite;
    }

    public function opening(): string
    {
        return $this->opening;
    }

    public function persona(): string
    {
        return $this->persona;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function needs(): array
    {
        return $this->needs;
    }

    public function unavailable(): ?string
    {
        return parent::unavailable() ?? ($this->unavailable ? ($this->unavailable)() : null);
    }

    public function prepare(): void
    {
        $this->state = $this->prepare ? (($this->prepare)() ?? []) : [];
    }

    public function checks(EvalTranscript $transcript): array
    {
        return ($this->checks)($transcript, $this->state, $this->user());
    }

    public function rubric(): array
    {
        return $this->rubric;
    }
}
