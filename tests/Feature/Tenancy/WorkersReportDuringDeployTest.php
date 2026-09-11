<?php

namespace Tests\Feature\Tenancy;

use App\Support\WorkerHeartbeat;
use App\Support\WorkerProcesses;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A deploy restarts the workers while the application is in maintenance mode,
 * and a worker skips its rounds then without firing Looping. With only the
 * loop to report from, every deploy ended in "the worker runs older code".
 *
 * And telling which process is a leftover went by age: the worker the unit
 * had just started was a minute old by the time that was asked, and got
 * stopped. It goes by the systemd unit now, and a worker of another
 * installation on the same server is not counted at all.
 */
class WorkersReportDuringDeployTest extends TestCase
{
    public function test_a_worker_reports_the_moment_it_starts(): void
    {
        $argv = $_SERVER['argv'];
        $_SERVER['argv'] = ['artisan', 'queue:work', '--queue=provisioning'];

        try {
            WorkerHeartbeat::listen();
        } finally {
            $_SERVER['argv'] = $argv;
        }

        Cache::forget(WorkerHeartbeat::key('provisioning'));
        Cache::forget(WorkerHeartbeat::codeKey('provisioning'));

        event(new WorkerStarting('database', 'provisioning', new WorkerOptions));

        $this->assertNotNull(WorkerHeartbeat::beatFor('provisioning'), 'a worker should report before its first round');
        $this->assertSame(WorkerHeartbeat::codeVersion(), WorkerHeartbeat::codeFor('provisioning'));
    }

    public function test_the_unit_is_read_from_the_control_group(): void
    {
        $this->assertSame('lavoro-worker', WorkerProcesses::unitOf("0::/system.slice/lavoro-worker.service\n"));
        $this->assertSame('lavoro-provisioning', WorkerProcesses::unitOf("1:name=systemd:/system.slice/lavoro-provisioning.service\n"));
        $this->assertNull(WorkerProcesses::unitOf("0::/user.slice/user-1000.slice/session-4.scope\n"));
    }

    public function test_only_the_workers_of_this_installation_count(): void
    {
        $worker = fn (array $overrides) => [
            'user' => 'lavoro', 'directory' => '', 'unit' => null, ...$overrides,
        ];

        config(['database.connections.provisioner.username' => 'lavoro_provisioner']);
        $here = realpath(base_path()) ?: base_path();

        $this->assertTrue(WorkerProcesses::belongsHere($worker(['unit' => 'lavoro-worker'])), 'started by our unit');
        $this->assertTrue(WorkerProcesses::belongsHere($worker(['directory' => $here])), 'started by hand, here');
        $this->assertTrue(WorkerProcesses::belongsHere($worker(['user' => 'lavoro_provisioner'])),
            'the provisioner, whose directory cannot be read from here');
        $this->assertFalse(WorkerProcesses::belongsHere($worker(['directory' => '/home/spee/public_html'])));
        $this->assertFalse(WorkerProcesses::belongsHere($worker(['user' => 'spee'])),
            'another installation on the same server');

        $this->assertFalse(WorkerProcesses::inUnit($worker(['directory' => $here])), 'by hand is not in a unit');
    }
}
