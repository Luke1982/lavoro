<?php

namespace App\Mail\Concerns;

/**
 * Files made in memory going along with a mail, such as a quote on paper and
 * the terms it is offered under: name, contents and type, nothing on disk.
 */
trait AttachesFiles
{
    /** @param array<int, array{name: string, data: string, mime: string}> $files */
    protected function attachFiles(array $files): void
    {
        foreach ($files as $file) {
            $this->attachData($file['data'], $file['name'], ['mime' => $file['mime']]);
        }
    }
}
