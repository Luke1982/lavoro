<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Domain\Assistant\AllowanceGate;
use App\Domain\Assistant\AssistantContext;
use App\Domain\Assistant\Contracts\ModelFailure;
use App\Domain\Assistant\Contracts\ModelUnavailable;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\Providers\OpenAiCompatibleModel;
use App\Domain\Planning\TechnicianAvailability;
use App\Domain\Signals\ActivityBuffer;
use App\Domain\Signals\Signals;
use App\Domain\Tools\ToolRegistry;
use App\Exceptions\GraphNotConfigured;
use App\Jobs\Google\DeleteEventFromGoogleJob;
use App\Jobs\Google\PushEventJob;
use App\Listeners\ApplyTenantSender;
use App\Listeners\CopyMailToSentFolder;
use App\Mail\Transports\GraphTransport;
use App\Models\Assistant;
use App\Models\CalendarGrant;
use App\Models\Company;
use App\Models\Event as EventModel;
use App\Models\GeneralSetting;
use App\Models\GoogleSyncedEvent;
use App\Models\StandardAttachment;
use App\Models\StandardEmail;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserUnavailability;
use App\Observers\EventObserver;
use App\Observers\PersonalAccessTokenObserver;
use App\Observers\TicketObserver;
use App\Observers\UserObserver;
use App\Policies\AssistantPolicy;
use App\Policies\CalendarGrantPolicy;
use App\Policies\EventPolicy;
use App\Policies\StandardAttachmentPolicy;
use App\Policies\StandardEmailPolicy;
use App\Policies\UserUnavailabilityPolicy;
use App\Services\AssistantAllowance;
use App\Support\ForgetsTenantState;
use App\Support\MailerState;
use App\Support\TenantMailTransport;
use App\Support\TenantState;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ActivityBuffer::class);
        $this->app->singleton(Signals::class);

        $this->app->singleton(AssistantContext::class);

        /**
         * Shared for the length of one request, so the tool and the crew planner
         * read the diary once between them rather than once each.
         *
         * Scoped rather than a singleton on purpose: it caches a window of the
         * diary, and a queue worker that lives for hours would go on answering
         * from the one it read this morning.
         */
        $this->app->scoped(TechnicianAvailability::class);

        $this->app->tag([
            ActivityBuffer::class,
            Signals::class,
            AssistantContext::class,
            TechnicianAvailability::class,
            MailerState::class,
        ], ForgetsTenantState::class);

        $this->app->singleton(
            ToolRegistry::class,
            fn () => new ToolRegistry(config('assistant.tools', [])),
        );

        /**
         * The timeout is set here rather than left to the SDK, whose own default
         * is documented as advisory: it is enforced by whichever PSR client is
         * installed, which may not enforce it at all. Without it a supplier that
         * stops answering holds a worker until PHP itself gives up, and the box
         * on screen spins the whole time.
         */
        $this->app->bind(
            AnthropicClient::class,
            fn () => new AnthropicClient(
                apiKey: (string) config('assistant.providers.anthropic.api_key'),
                requestOptions: ['timeout' => (float) config('assistant.timeout_seconds', 120)],
            ),
        );

        /**
         * One name in config decides who answers. Anthropic has an API of its
         * own and so an adapter of its own; everything else speaks OpenAI's
         * chat API and shares one.
         */
        $this->app->bind(TalksToModel::class, function () {
            $provider = config('assistant.provider');
            $driver = config('assistant.providers.' . $provider . '.driver');

            if ($driver === null) {
                throw new ModelUnavailable(
                    ModelFailure::other,
                    'Onbekende AI-aanbieder: ' . $provider,
                );
            }

            return $driver === OpenAiCompatibleModel::class
                ? OpenAiCompatibleModel::fromConfig($provider)
                : $this->app->make($driver);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * `php artisan serve` runs the real web server as a child process and
         * strips its environment down to an allowlist, so the machine's stock
         * php.ini limits apply there — far below what DocumentStoreRequest
         * promises. Point PHP at .php.d (the leading colon keeps the default
         * conf.d) and let the variable through, so any locally started dev
         * server accepts what the app accepts. Production runs FPM behind
         * nginx and must be raised in server config instead.
         */
        $needs_ini_scan_dir = $this->app->environment('local')
            && $this->app->runningInConsole()
            && getenv('PHP_INI_SCAN_DIR') === false;

        if ($needs_ini_scan_dir) {
            $scan_dir = ':' . base_path('.php.d');
            putenv('PHP_INI_SCAN_DIR=' . $scan_dir);
            $_ENV['PHP_INI_SCAN_DIR'] = $scan_dir;
            $_SERVER['PHP_INI_SCAN_DIR'] = $scan_dir;
            ServeCommand::$passthroughVariables[] = 'PHP_INI_SCAN_DIR';
        }

        User::observe(UserObserver::class);
        PersonalAccessToken::observe(PersonalAccessTokenObserver::class);

        /**
         * Geen model om een policy aan te hangen, wel iets om af te schermen:
         * de sleutels van de koppelingen zitten achter dezelfde toestemming als
         * de rest van het technisch beheer.
         */
        /**
         * De poort die bepaalt of de assistent nog mag antwoorden. Als
         * interface gebonden zodat een test hem kan vervangen zonder aan de
         * echte teller te zitten.
         */
        $this->app->bind(
            AllowanceGate::class,
            AssistantAllowance::class,
        );

        Gate::define('technical.management', fn ($user) => $user->hasPermission('technical.management'));

        Gate::policy(Assistant::class, AssistantPolicy::class);
        Gate::policy(EventModel::class, EventPolicy::class);
        Gate::policy(CalendarGrant::class, CalendarGrantPolicy::class);
        Gate::policy(UserUnavailability::class, UserUnavailabilityPolicy::class);
        Gate::policy(StandardEmail::class, StandardEmailPolicy::class);
        Gate::policy(StandardAttachment::class, StandardAttachmentPolicy::class);

        EventModel::observe(EventObserver::class);
        Ticket::observe(TicketObserver::class);

        Queue::before(function () {
            /**
             * A job that restores its actor leaves that person signed in for the
             * rest of a long running worker, so the next job would inherit both
             * their name and their permissions. Every job starts with nobody.
             */
            Auth::forgetUser();

            TenantState::flush();
        });

        Event::listen('eloquent.attached: App\Models\Event', function ($event_class, $payload) {
            [$model, $relation, $ids] = $payload + [null, null, []];
            if (!$model instanceof EventModel) {
                return;
            }
            PushEventJob::dispatch($model->id);
        });

        Event::listen('eloquent.detached: App\Models\Event', function ($event_class, $payload) {
            [$model, $relation, $ids] = $payload + [null, null, []];
            if (!$model instanceof EventModel) {
                return;
            }
            PushEventJob::dispatch($model->id);
            $event_id = $model->id;
            $still_relevant_user_ids = array_unique(array_merge(
                $model->owners()->wherePivot('type', 'owner')->pluck('users.id')->all(),
                $model->executingUsers()->pluck('users.id')->all(),
            ));
            $stale_mappings = GoogleSyncedEvent::whereHas(
                'syncedCalendar',
                fn ($q) => $q->whereNotIn('owner_user_id', $still_relevant_user_ids),
            )->where('event_id', $event_id)->get();
            foreach ($stale_mappings as $mapping) {
                DeleteEventFromGoogleJob::dispatch(
                    $mapping->id,
                    $mapping->google_synced_calendar_id,
                    $mapping->google_event_id,
                );
            }
        });

        /**
         * De mailer van de klant. Welke server erachter zit — Microsoft 365 of
         * een eigen SMTP — staat in de instellingen van die klant. Lui
         * opgebouwd, dus pas op het moment van versturen, als de tenant vaststaat.
         */
        Mail::extend('tenant', fn () => app(TenantMailTransport::class)->make());

        Mail::extend('graph', function () {
            $setting = fn (string $key) => tenancy()->initialized
                ? GeneralSetting::get($key)
                : null;

            $azure_tenant = $setting('graph_azure_tenant_id');
            $client_id = $setting('graph_client_id');
            $secret = $setting('graph_client_secret');
            $user_id = $setting('graph_user_id');

            /**
             * Alle vier of niets, en geen terugval op .env. Mail sturen uit de
             * mailbox van een ander bedrijf zet de verkeerde afzender op de post
             * van een klant, en dat is erger dan niet versturen: er komt geen
             * foutmelding, het bericht komt gewoon van iemand anders.
             */
            if (!filled($azure_tenant) || !filled($client_id) || !filled($secret) || !filled($user_id)) {
                throw new GraphNotConfigured;
            }

            return new GraphTransport(
                tenantId: $azure_tenant,
                clientId: $client_id,
                clientSecret: $secret,
                fromAddress: GeneralSetting::get('mail_from_address', $user_id),
                userId: $user_id,
                graphEndpoint: config('services.graph.endpoint'),
                dispatcher: app('events'),
                logger: app('log')->channel()
            );
        });

        Event::listen(MessageSending::class, ApplyTenantSender::class);
        Event::listen(MessageSent::class, CopyMailToSentFolder::class);

        Inertia::share('company', function () {
            if (!tenancy()->initialized) {
                return null;
            }

            $company = Company::where('is_main', true)->first();
            if (!$company) {
                return null;
            }
            $logo_url = $company->logo_path ? url("/files/companies/{$company->id}/logo") : null;

            return [
                'name' => $company->name,
                'logo_url' => $logo_url,
            ];
        });
    }
}
