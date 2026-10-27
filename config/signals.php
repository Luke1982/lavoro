<?php

return [

    /*
    |--------------------------------------------------------------------------
    | How many signals one unit of work may raise
    |--------------------------------------------------------------------------
    |
    | A backstop against something running away, not a budget to plan against.
    | Loops are caught by the chain itself; see App\Domain\Signals\Signals.
    |
    | A web request is small by nature. A console command or a queued job is
    | not: rebuilding the demo customer writes a whole company, and every record
    | announces itself.
    |
    */

    'max_per_request' => (int) env('SIGNALS_MAX_PER_REQUEST', 1000),

    'max_per_console_run' => (int) env('SIGNALS_MAX_PER_CONSOLE_RUN', 250000),

];
