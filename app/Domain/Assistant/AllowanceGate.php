<?php

namespace App\Domain\Assistant;

interface AllowanceGate
{
    public function hasRoom(): bool;

    public function record(UsageCost $cost, int $user_id): void;
}
