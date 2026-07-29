<?php

use App\Domain\Assistant\Providers\AnthropicModel;
use App\Domain\Assistant\Providers\OpenAiCompatibleModel;
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
    |---------------------------------------------------------------------------
    | Which supplier answers
    |---------------------------------------------------------------------------
    |
    | Set ASSISTANT_PROVIDER to any key below. Everything outside the adapters
    | works in neutral terms, so nothing else in the application changes.
    |
    | Anthropic has an API of its own and so has an adapter of its own. Every
    | other entry here speaks OpenAI's chat API, which is why they share one
    | adapter and differ only in a URL, a key and a model name.
    |
    */

    'provider' => env('ASSISTANT_PROVIDER', 'anthropic'),

    /*
    | capability is how hard a problem this model can be trusted with, on the
    | same one-to-ten scale the tools use. The cheapest model that clears what a
    | conversation needs is the one that answers it.
    |
    | These are estimates, not measurements. Correct them against
    | docs/assistant-testvragen.md: a model that keeps choosing the wrong tool is
    | rated too high, and fixing that is a config change.
    */

    'providers' => [

        'anthropic' => [
            'driver' => AnthropicModel::class,
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
            'api_key' => env('ANTHROPIC_API_KEY'),
            'capability' => 9,
        ],

        'deepseek' => [
            'driver' => OpenAiCompatibleModel::class,
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
            'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'api_key' => env('DEEPSEEK_API_KEY'),
            'capability' => 7,
        ],

        'mistral' => [
            'driver' => OpenAiCompatibleModel::class,
            'base_url' => env('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'),
            'model' => env('MISTRAL_MODEL', 'mistral-large-latest'),
            'api_key' => env('MISTRAL_API_KEY'),
            'capability' => 7,
        ],

        'qwen' => [
            'driver' => OpenAiCompatibleModel::class,
            'base_url' => env('QWEN_BASE_URL', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1'),
            'model' => env('QWEN_MODEL', 'qwen-plus'),
            'api_key' => env('QWEN_API_KEY'),
            'capability' => 6,
        ],

        'moonshot' => [
            'driver' => OpenAiCompatibleModel::class,
            'base_url' => env('MOONSHOT_BASE_URL', 'https://api.moonshot.ai/v1'),
            'model' => env('MOONSHOT_MODEL', 'kimi-k2-0711-preview'),
            'api_key' => env('MOONSHOT_API_KEY'),
            'capability' => 7,
        ],

        'openai' => [
            'driver' => OpenAiCompatibleModel::class,
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('OPENAI_MODEL', 'gpt-4.1'),
            'api_key' => env('OPENAI_API_KEY'),
            'capability' => 8,
        ],

    ],

    'max_tokens' => (int) env('ASSISTANT_MAX_TOKENS', 16000),

    /*
    |---------------------------------------------------------------------------
    | What a turn costs
    |---------------------------------------------------------------------------
    |
    | Dollars per million tokens, keyed by the model name the supplier reports
    | back. Cached input is priced separately in both directions because the
    | rates genuinely differ — writing a cache costs more than plain input,
    | reading one costs a fraction.
    |
    | VERIFY THESE BEFORE BILLING ANYONE ON THEM. They were written on
    | 2026-07-29 from published list prices and suppliers change them without
    | telling you. A model with no entry is recorded as costing nothing and logs
    | a warning, which is deliberate: a nought that shows up beats a plausible
    | number that is wrong.
    |
    | The Anthropic figures are list price. Sonnet is on introductory pricing
    | until 2026-08-31, so today's rows slightly overstate the real bill — the
    | safe direction for a number that decides when to cut someone off.
    |
    */

    'pricing' => [
        'claude-sonnet-5' => ['input' => 3.00, 'output' => 15.00, 'cache_write' => 3.75, 'cache_read' => 0.30],
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00, 'cache_write' => 1.25, 'cache_read' => 0.10],

        'deepseek-chat' => ['input' => 0.27, 'output' => 1.10, 'cache_write' => 0.27, 'cache_read' => 0.07],
        'deepseek-reasoner' => ['input' => 0.55, 'output' => 2.19, 'cache_write' => 0.55, 'cache_read' => 0.14],

        'mistral-large-latest' => ['input' => 2.00, 'output' => 6.00, 'cache_write' => 2.00, 'cache_read' => 2.00],
        'mistral-small-latest' => ['input' => 0.20, 'output' => 0.60, 'cache_write' => 0.20, 'cache_read' => 0.20],

        'qwen-plus' => ['input' => 0.40, 'output' => 1.20, 'cache_write' => 0.40, 'cache_read' => 0.16],
        'qwen-turbo' => ['input' => 0.05, 'output' => 0.20, 'cache_write' => 0.05, 'cache_read' => 0.02],

        'kimi-k2-0711-preview' => ['input' => 0.60, 'output' => 2.50, 'cache_write' => 0.60, 'cache_read' => 0.15],

        'gpt-4.1' => ['input' => 2.00, 'output' => 8.00, 'cache_write' => 2.00, 'cache_read' => 0.50],
        'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60, 'cache_write' => 0.40, 'cache_read' => 0.10],
    ],

    /*
    | Suppliers bill in dollars and tenants are billed in euros, so the rate used
    | is written onto every row. Without that, a currency move would silently
    | rewrite what last month cost.
    */

    'eur_per_usd' => (float) env('ASSISTANT_EUR_PER_USD', 0.92),

];
