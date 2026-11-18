<?php

namespace App\Enums;

/**
 * Where an import is. Uploaded and mapped can still be changed and thrown away;
 * from queued on the rows are being written and the screen only follows along.
 */
enum CatalogImportStatus: string
{
    case uploaded = 'uploaded';
    case mapped = 'mapped';
    case queued = 'queued';
    case running = 'running';
    case done = 'done';
    case failed = 'failed';

    public function isEditable(): bool
    {
        return $this === self::uploaded || $this === self::mapped;
    }

    public function isBusy(): bool
    {
        return $this === self::queued || $this === self::running;
    }
}
