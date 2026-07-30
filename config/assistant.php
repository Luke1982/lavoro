<?php

use App\Domain\Assistant\Providers\AnthropicModel;
use App\Domain\Assistant\Providers\OpenAiCompatibleModel;
use App\Domain\Tools\Read\FindAssetTool;
use App\Domain\Tools\Read\FindAvailableTechnicianTool;
use App\Domain\Tools\Read\FindCustomerTool;
use App\Domain\Tools\Read\FindProductTool;
use App\Domain\Tools\Read\FindTicketTool;
use App\Domain\Tools\Read\ReadDocumentationTool;
use App\Domain\Tools\Read\ResearchTicketTool;
use App\Domain\Tools\Read\SearchActivityTool;
use App\Domain\Tools\Read\SearchServiceOrderTool;
use App\Domain\Tools\Read\SummarizeCustomerTool;
use App\Domain\Tools\Write\AddServiceOrderTaskTool;
use App\Domain\Tools\Write\CreateEventTool;
use App\Domain\Tools\Write\CreateServiceOrderTool;
use App\Domain\Tools\Write\CreateTicketTool;

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
        FindTicketTool::class,
        FindProductTool::class,
        ReadDocumentationTool::class,
        ResearchTicketTool::class,
        SearchActivityTool::class,
        SummarizeCustomerTool::class,
        FindAvailableTechnicianTool::class,
        CreateEventTool::class,
        CreateTicketTool::class,
        CreateServiceOrderTool::class,
        AddServiceOrderTaskTool::class,
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

        /*
        | The same supplier and the same key, on its cheap model. Sorting questions
        | is worth nothing without somewhere cheaper to send the easy ones, and
        | until a second supplier has a key this is the only cheaper place there
        | is — a third of the price for looking something up.
        |
        | The capability is deliberately modest. It answers lookups; anything that
        | needs weighing goes past it to the model above.
        */
        'anthropic_light' => [
            'driver' => AnthropicModel::class,
            'model' => env('ANTHROPIC_LIGHT_MODEL', 'claude-haiku-4-5-20251001'),
            'api_key' => env('ANTHROPIC_API_KEY'),
            'capability' => 4,
        ],

        /*
        | Not for answering anything — this one only reads a question and says how
        | hard it looks, so the answer itself can go to something that fits. It is
        | marked reserved so it is never chosen as an answering model, however
        | cheap it is, and capped to a handful of tokens because the reply is a
        | number.
        */
        'sorter' => [
            'driver' => AnthropicModel::class,
            'model' => env('ASSISTANT_SORTER_MODEL', 'claude-haiku-4-5-20251001'),
            'api_key' => env('ANTHROPIC_API_KEY'),
            'capability' => 1,
            'max_tokens' => 16,
            'reserved' => true,
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
    |--------------------------------------------------------------------------
    | How long one call to a supplier may take
    |--------------------------------------------------------------------------
    |
    | Both adapters read this, so switching supplier does not quietly change how
    | long a stuck request holds a worker. It is deliberately not left to the
    | Anthropic SDK's own default: that one is documented as advisory, enforced
    | by whichever PSR client happens to be installed, which may be not at all.
    |
    | A question that needs six rounds of tools spends this on each of them, so
    | this is the ceiling per call rather than for the whole answer.
    */
    'timeout_seconds' => (int) env('ASSISTANT_TIMEOUT_SECONDS', 120),

    /*
    |--------------------------------------------------------------------------
    | Which entry reads the question before it is answered
    |--------------------------------------------------------------------------
    |
    | Set to null to switch the sorting off, in which case the model is chosen by
    | the hardest tool somebody could reach — which is what it did before, and puts
    | everybody on the dearest model as soon as one hard tool is shared by all.
    */
    'question_sorter' => env('ASSISTANT_QUESTION_SORTER', 'sorter'),

    /*
    |--------------------------------------------------------------------------
    | Which documents count as documentation
    |--------------------------------------------------------------------------
    |
    | Categories are named by whoever set the system up, so there is no column
    | saying "this is a manual". These words are matched against the category
    | name, case-insensitively, anywhere in it — "Technische documentatie" and
    | "Handleidingen" both land on the list, "Facturen" and "Contracten" do not.
    |
    | Deliberately a list rather than a guess. A contract or an invoice attached
    | to a product is somebody's commercial paperwork, and sending that to a
    | supplier to answer "what is the refrigerant" would be nobody's intention.
    */
    'documentation_categories' => [
        'documentatie',
        'handleiding',
        'handleidingen',
        'manual',
        'datasheet',
        'specificatie',
        'specificaties',
        'technisch',
        'technische',
        'instructie',
        'instructies',
    ],

    /*
    | Files bigger than this are left out. A supplier has its own ceiling, and one
    | oversized scan should not be the reason a question fails.
    */
    'max_document_kilobytes' => (int) env('ASSISTANT_MAX_DOCUMENT_KB', 8192),

    /*
    | How many go with one question, in case a product has a shelf of them.
    */
    'max_documents_per_question' => (int) env('ASSISTANT_MAX_DOCUMENTS', 3),

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
        /*
        | Keyed by the exact model id that is sent, not by a family name. A price
        | that does not match is not a small mistake: an unpriced model sorts last
        | so it is never chosen, and its usage is recorded at nought — invisible
        | twice over.
        */
        'claude-haiku-4-5-20251001' => ['input' => 1.00, 'output' => 5.00, 'cache_write' => 1.25, 'cache_read' => 0.10],

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
