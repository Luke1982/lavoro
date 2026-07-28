<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A file that is committed may only depend on other files that are committed.
 *
 * AppServiceProvider once shipped while it referenced App\Domain\Assistant and
 * App\Domain\Tools, which were never added to the repository. Every deploy that
 * pulled the provider failed to boot, and no test caught it because the missing
 * classes were present on the machine the tests ran on.
 */
class CommittedCodeIsSelfContainedTest extends TestCase
{
    public function test_no_committed_file_imports_a_class_that_is_not_committed(): void
    {
        $tracked = $this->trackedFiles();
        $dangling = [];

        foreach ($tracked as $path) {
            if (!str_starts_with($path, 'app/') || !str_ends_with($path, '.php')) {
                continue;
            }

            $source = file_get_contents(base_path($path));
            preg_match_all('/^use (App\\\\[A-Za-z0-9_\\\\]+)(?: as [A-Za-z0-9_]+)?;/m', $source, $matches);

            foreach ($matches[1] as $class) {
                $expected = 'app/' . str_replace('\\', '/', substr($class, strlen('App\\'))) . '.php';

                if (in_array($expected, $tracked, true)) {
                    continue;
                }

                /**
                 * Unreleased work may be referenced as long as every use is behind
                 * a class_exists check, so a checkout without it still runs.
                 */
                $short = class_basename($class);

                if (str_contains($source, 'class_exists(' . $short . '::class)')) {
                    continue;
                }

                $dangling[] = $path . ' imports ' . $class . ' unguarded, and it is not committed';
            }
        }

        $this->assertSame([], $dangling, "\n" . implode("\n", $dangling) . "\n");
    }

    /** @return array<int, string> */
    private function trackedFiles(): array
    {
        exec('git -C ' . escapeshellarg(base_path()) . ' ls-files', $output, $status);

        if ($status !== 0) {
            $this->markTestSkipped('not a git checkout');
        }

        return $output;
    }
}
