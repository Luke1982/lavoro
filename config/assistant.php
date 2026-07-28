<?php

use App\Domain\Tools\Read\FindAssetTool;
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

    'api_key' => env('ANTHROPIC_API_KEY'),

    'model' => env('ASSISTANT_MODEL', 'claude-sonnet-5'),

    'max_tokens' => (int) env('ASSISTANT_MAX_TOKENS', 16000),

];
