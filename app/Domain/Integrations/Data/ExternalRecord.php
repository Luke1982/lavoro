<?php

namespace App\Domain\Integrations\Data;

/**
 * One record as the other side has it: its id there, and its contents in the
 * shape Lavoro speaks.
 */
class ExternalRecord
{
    public function __construct(
        public string $id,
        public RecordData $data,
    ) {}
}
