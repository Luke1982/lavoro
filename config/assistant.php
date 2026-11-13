<?php

use App\Domain\Assistant\Providers\AnthropicModel;
use App\Domain\Assistant\Providers\OpenAiCompatibleModel;
use App\Domain\Tools\Read\AskWhichOneTool;
use App\Domain\Tools\Read\FindAppointmentTool;
use App\Domain\Tools\Read\FindAssetTool;
use App\Domain\Tools\Read\FindAvailableTechnicianTool;
use App\Domain\Tools\Read\FindCustomerTool;
use App\Domain\Tools\Read\FindPlaceholderRecordsTool;
use App\Domain\Tools\Read\FindProductTool;
use App\Domain\Tools\Read\FindTicketTool;
use App\Domain\Tools\Read\ListMacrosTool;
use App\Domain\Tools\Read\NoteFileTool;
use App\Domain\Tools\Read\ReadDocumentationTool;
use App\Domain\Tools\Read\ReadFileTool;
use App\Domain\Tools\Read\ReadManualTool;
use App\Domain\Tools\Read\ReportFindingsTool;
use App\Domain\Tools\Read\ResearchTicketTool;
use App\Domain\Tools\Read\SearchActivityTool;
use App\Domain\Tools\Read\SearchServiceOrderTool;
use App\Domain\Tools\Read\SummarizeCustomerTool;
use App\Domain\Tools\Read\ViewImagesTool;
use App\Domain\Tools\Write\AddServiceOrderTaskTool;
use App\Domain\Tools\Write\CreateAssetTool;
use App\Domain\Tools\Write\CreateCustomerTool;
use App\Domain\Tools\Write\CreateEventTool;
use App\Domain\Tools\Write\CreateLocationTool;
use App\Domain\Tools\Write\CreateMaintenanceContractTool;
use App\Domain\Tools\Write\CreateProductTool;
use App\Domain\Tools\Write\CreateServiceOrderTool;
use App\Domain\Tools\Write\CreateTicketTool;
use App\Domain\Tools\Write\RunMacroTool;
use App\Domain\Tools\Write\SaveDocumentTool;

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
        AskWhichOneTool::class,
        ReportFindingsTool::class,
        ViewImagesTool::class,
        FindPlaceholderRecordsTool::class,
        SearchServiceOrderTool::class,
        FindAssetTool::class,
        FindTicketTool::class,
        FindProductTool::class,
        FindAppointmentTool::class,
        ReadDocumentationTool::class,
        ResearchTicketTool::class,
        SearchActivityTool::class,
        SummarizeCustomerTool::class,
        FindAvailableTechnicianTool::class,
        CreateEventTool::class,
        CreateTicketTool::class,
        CreateServiceOrderTool::class,
        CreateProductTool::class,
        CreateAssetTool::class,
        AddServiceOrderTaskTool::class,
        ListMacrosTool::class,
        RunMacroTool::class,
        ReadManualTool::class,
        SaveDocumentTool::class,
        CreateCustomerTool::class,
        CreateLocationTool::class,
        CreateMaintenanceContractTool::class,
        NoteFileTool::class,
        ReadFileTool::class,
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
    | Correct the ratings against docs/development/assistant-tests.md: a model that
    | keeps choosing the wrong tool is rated too high, and fixing that is a
    | config change.
    |
    | Haiku 5.5 and DeepSeek Flash were measured against Sonnet on 2026-11-10:
    | that list plus five planning and create questions, 26 in all. Haiku
    | chose the same tools and gave the same answers on every one. DeepSeek
    | went wrong on three, each time by looking up a technician as a customer,
    | and once told a planner that a colleague did not exist. The other
    | ratings are estimates.
    */

    'providers' => [

        'anthropic' => [
            'driver' => AnthropicModel::class,
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
            /** De enige adapter die bijlagen echt doorgeeft; foto's dwingen deze aanbieder af. */
            'sees_images' => true,
            /** En dezelfde adapter leest pdf's; een bijlage dwingt deze aanbieder net zo goed af. */
            'reads_documents' => true,
            /**
             * Internet opzoeken, door de leverancier zelf uitgevoerd. Kost circa
             * een cent per zoekactie plus de tokens van wat er gelezen wordt;
             * max_uses houdt één vraag bij een handvol zoekacties.
             */
            'web_search' => env('ANTHROPIC_WEB_SEARCH', true),
            'web_search_max_uses' => env('ANTHROPIC_WEB_SEARCH_MAX_USES', 3),
            'api_key' => env('ANTHROPIC_API_KEY'),
            'capability' => 9,
        ],

        'deepseek' => [
            'driver' => OpenAiCompatibleModel::class,
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
            'model' => env('DEEPSEEK_MODEL', 'deepseek-flash'),
            'api_key' => env('DEEPSEEK_API_KEY'),
            'capability' => 4,
            /**
             * deepseek-flash thinks before it answers unless told not to.
             * deepseek-chat, the name sent before, was the same model with
             * thinking off, and that is how it was measured.
             */
            'request' => ['thinking' => ['type' => 'disabled']],
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
        | The same supplier and the same key, on its cheap model: a twentieth of
        | the price of Sonnet, and cheaper than DeepSeek too. Rated 7 on the
        | measurement above. ResearchTicket (8) was not part of it, so that
        | stays with Sonnet.
        */
        'anthropic_light' => [
            'driver' => AnthropicModel::class,
            'model' => env('ANTHROPIC_LIGHT_MODEL', 'claude-haiku-5-5'),
            'api_key' => env('ANTHROPIC_API_KEY'),
            'capability' => 7,
            /** Reading a datasheet is not the same work as weighing one; the cheap one can do it. */
            'reads_documents' => true,
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
            'model' => env('ASSISTANT_SORTER_MODEL', 'claude-haiku-5-5'),
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
    | What all the files together may come to, once base64 has added its third.
    | Suppliers refuse a request over a size of their own — Anthropic at 32 MB —
    | and refusing takes the question with it, so this leaves room for the prompt,
    | the tools and the conversation as well.
    */
    'max_payload_kilobytes' => (int) env('ASSISTANT_MAX_PAYLOAD_KB', 16384),

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
    | VERIFY THESE BEFORE BILLING ANYONE ON THEM. Suppliers change them without
    | telling you. The Sonnet, Haiku and DeepSeek rows were checked against the
    | suppliers' own price pages on 2026-11-10; the others date from
    | 2026-07-29. A model with no entry is recorded as costing nothing and logs
    | a warning, which is deliberate: a nought that shows up beats a plausible
    | number that is wrong.
    |
    | DeepSeek charges double on weekdays from 01:00 to 04:00 and from 06:00 to
    | 10:00 UTC; the second window is the Dutch working morning. Its rows hold
    | that peak price, so a call outside those hours is recorded at twice what
    | it cost.
    |
    | Haiku 5.5 charges five times as much for a call whose prompt, cached
    | tokens included, is over 100,000 tokens. long_prompt holds those rates.
    |
    */

    /*
    |---------------------------------------------------------------------------
    | Waar gemelde gesprekken landen
    |---------------------------------------------------------------------------
    |
    | Een gemeld gesprek is een bestand met de vragen, de antwoorden, en wat de
    | tools werden meegegeven en teruggaven. Dat laatste is de helft die in een
    | overgetypt gesprek ontbreekt en waar de fouten in blijken te zitten.
    |
    | Het bevat dus klantgegevens. Het staat op een niet-openbare schijf, het
    | hoort niet in versiebeheer, en het valt onder dezelfde bewaartermijn als de
    | vragen zelf.
    |
    */

    'reports_disk' => env('ASSISTANT_REPORTS_DISK', 'local'),

    /*
    | Wie een melding in de inbox krijgt, met het gesprek als bijlage. Leeg laten
    | zet het mailen uit; het bestand wordt dan alleen op de schijf bewaard.
    */

    'reports_mail_to' => env('ASSISTANT_REPORTS_MAIL', 'info@majorlabel.nl'),

    /*
    | Foto's bij een vraag: hoeveel en hoe groot. Basis64 groeit een derde boven
    | de bestandsgrootte, dus 4000 kB hier is ruwweg een foto van 3 MB.
    */

    'max_images' => env('ASSISTANT_MAX_IMAGES', 4),

    'max_image_kb' => env('ASSISTANT_MAX_IMAGE_KB', 4000),

    /*
    | Bestanden bij een vraag: pdf's en platte tekst. Krapper dan foto's, want een
    | pdf van twintig pagina's is tienduizenden tokens — en twee bestanden naast
    | elkaar leggen is de vraag die mensen stellen, drie vrijwel nooit.
    */

    'max_documents' => env('ASSISTANT_MAX_DOCUMENTS', 2),

    'max_document_kb' => env('ASSISTANT_MAX_DOCUMENT_KB', 4000),

    /** Hoeveel dagen geparkeerde foto's en bestanden wachten op een keuze voordat ze weggaan. */
    'photo_days' => env('ASSISTANT_PHOTO_DAYS', 7),

    'reports_path' => env('ASSISTANT_REPORTS_PATH', 'assistant-reports'),

    'pricing' => [
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.10],
        'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.20],
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        /*
        | Keyed by the exact model id that is sent, not by a family name. A price
        | that does not match is not a small mistake: an unpriced model sorts last
        | so it is never chosen, and its usage is recorded at nought — invisible
        | twice over.
        */
        'claude-haiku-5-5' => [
            'input' => 0.10, 'output' => 0.50, 'cache_write' => 0.125, 'cache_read' => 0.01,
            'long_prompt' => [
                'above_tokens' => 100_000,
                'input' => 0.50, 'output' => 2.50, 'cache_write' => 0.625, 'cache_read' => 0.05,
            ],
        ],
        'claude-haiku-4-5-20251001' => ['input' => 1.00, 'output' => 5.00, 'cache_write' => 1.25, 'cache_read' => 0.10],

        'deepseek-flash' => ['input' => 0.30, 'output' => 1.20, 'cache_write' => 0.30, 'cache_read' => 0.006],
        'deepseek-v4-pro' => ['input' => 1.32, 'output' => 3.96, 'cache_write' => 1.32, 'cache_read' => 0.044],

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
