<?php

namespace Tests\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * A second connection to the central database, outside the test transaction.
 *
 * The suite runs every test in a transaction on the central connection, and
 * that has two consequences no test can work around from the inside: what it
 * writes is invisible to anything that does not share the connection, and what
 * it reads is the snapshot from the moment the transaction opened -- so a row
 * another connection committed in the meantime is simply not there.
 *
 * Both come up in the same place: a command or a service that makes its own
 * connections, or writes MySQL accounts, which are not rolled back at all.
 * Whatever is written here has to be cleared away by hand.
 */
trait OutsideTheTestTransaction
{
    protected function outsideTheTransaction(): Connection
    {
        config(['database.connections.committing' => config('database.connections.central')]);

        return DB::connection('committing');
    }
}
