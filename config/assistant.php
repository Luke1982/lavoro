<?php

use App\Domain\Assistant\Providers\AnthropicModel;
use App\Domain\Tools\Read\FindAssetTool;
use App\Domain\Tools\Read\FindAvailableTechnicianTool;
use App\Domain\Tools\Read\FindCustomerTool;
use App\Domain\Tools\Read\SearchActivityTool;
use App\Domain\Tools\Read\SearchServiceOrderTool;
use App\Domain\Tools\Read\SummarizeCustomerTool;

return [

    /*
    |---------------------------------------------------------------------------
    | Tools
    |---------------------------------------------------------------------------
    |
    | Every capability the assistant has, listed by hand. A tool is a hole in the
    | wall between a language model and this database, so each one is here
    | because somebody decided to open it — never because a file appeared in a
    | directory.
    |
    | Order matters: tool definitions sit at the very front of the prompt, and
    | reordering them invalidates the cached prefix for everything after them.
    | Add to the end.
    |
    */

    'tools' => [
        FindCustomerTool::class,
        SearchServiceOrderTool::class,
        FindAssetTool::class,
        SearchActivityTool::class,
        SummarizeCustomerTool::class,
        FindAvailableTechnicianTool::class,
    ],

    /*
    |---------------------------------------------------------------------------
    | Result limits
    |---------------------------------------------------------------------------
    |
    | How many rows a read tool may return in one call. The ceiling exists to
    | protect the context window, not the database: a tool that returns three
    | hundred werkbonnen buries the answer it was asked for.
    |
    */

    'max_results' => 25,

    /*
    |---------------------------------------------------------------------------
    | Model
    |---------------------------------------------------------------------------
    |
    | max_tokens covers thinking and the written answer together, so it needs
    | room for both. Too tight and a turn stops halfway through its own sentence.
    |
    */

    /*
    | Which supplier answers. Swapping means pointing this at another adapter —
    | one class implementing TalksToModel. Nothing outside that class, and
    | nothing in the tools at all, knows who is on the other end.
    */

    'driver' => env('ASSISTANT_DRIVER', AnthropicModel::class),

    'api_key' => env('ANTHROPIC_API_KEY'),

    'model' => env('ASSISTANT_MODEL', 'claude-sonnet-5'),

    'max_tokens' => (int) env('ASSISTANT_MAX_TOKENS', 16000),

    /*
    |---------------------------------------------------------------------------
    | What a turn costs
    |---------------------------------------------------------------------------
    |
    | Dollars per million tokens, as Anthropic lists them. Cached input is a
    | separate rate in both directions: writing to the cache costs more than
    | ordinary input, reading from it costs a fraction. Both are recorded, so the
    | day caching is switched on the numbers stay honest.
    |
    | These are list prices. Sonnet is on introductory pricing until 2026-08-31,
    | so today's real bill is lower than what gets recorded — which errs towards
    | over-stating cost, and that is the safe direction for anything that decides
    | when to cut someone off.
    |
    */

    'pricing' => [
        'claude-sonnet-5' => ['input' => 3.00, 'output' => 15.00, 'cache_write' => 3.75, 'cache_read' => 0.30],
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00, 'cache_write' => 1.25, 'cache_read' => 0.10],
    ],

    /*
    | Anthropic bills in dollars and tenants are billed in euros, so the rate used
    | is written onto every row. Without that, a currency move would silently
    | rewrite what last month cost.
    */

    'eur_per_usd' => (float) env('ASSISTANT_EUR_PER_USD', 0.92),

];
