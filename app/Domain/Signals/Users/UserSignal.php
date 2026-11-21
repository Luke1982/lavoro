<?php

namespace App\Domain\Signals\Users;

use App\Domain\Signals\BaseSignal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Something happened to a person's account, recorded on that person.
 *
 * MajorLabel's own accounts are never written into a customer's trail: the
 * customer does not see those accounts anywhere else either, and a line about
 * one on a timeline or in an answer of the assistant would give them away. The
 * signal itself still goes out, so whatever reacts to it keeps working.
 */
abstract class UserSignal extends BaseSignal
{
    public function __construct(public User $user)
    {
        parent::__construct();
    }

    public function subject(): Model
    {
        return $this->user;
    }

    public function activityDescription(): ?string
    {
        return $this->user->isSuperAdmin() ? null : $this->describe();
    }

    public function changes(): array
    {
        return $this->user->isSuperAdmin() ? [] : $this->describeChanges();
    }

    abstract protected function describe(): ?string;

    /** @return array<int, array<string, mixed>> */
    protected function describeChanges(): array
    {
        return [];
    }
}
