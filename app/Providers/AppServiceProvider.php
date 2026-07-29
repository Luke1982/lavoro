<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Domain\Assistant\AssistantContext;
use App\Domain\Assistant\Contracts\ModelFailure;
use App\Domain\Assistant\Contracts\ModelUnavailable;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\Providers\OpenAiCompatibleModel;
use App\Domain\Signals\ActivityBuffer;
use App\Domain\Signals\Signals;
use App\Domain\Tools\ToolRegistry;
use App\Jobs\Google\DeleteEventFromGoogleJob;
use App\Jobs\Google\PushEventJob;
use App\Listeners\CopyMailToSentFolder;
use App\Mail\Transports\GraphTransport;
use App\Models\CalendarGrant;
use App\Models\Company;
use App\Models\Event as EventModel;
use App\Models\GoogleSyncedEvent;
use App\Models\StandardAttachment;
use App\Models\StandardEmail;
use App\Models\Ticket;
use App\Models\UserUnavailability;
use App\Observers\EventObserver;
use App\Observers\TicketObserver;
use App\Policies\CalendarGrantPolicy;
use App\Policies\EventPolicy;
use App\Policies\StandardAttachmentPolicy;
use App\Policies\StandardEmailPolicy;
use App\Policies\UserUnavailabilityPolicy;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

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

        $this->app->singleton(
            ToolRegistry::class,
            fn () => new ToolRegistry(config('assistant.tools', [])),
        );

        $this->app->bind(
            AnthropicClient::class,
            fn () => new AnthropicClient(apiKey: (string) config('assistant.providers.anthropic.api_key')),
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

            app(ActivityBuffer::class)->reset();
            app(Signals::class)->reset();

            app(AssistantContext::class)->reset();
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

        Mail::extend('graph', function () {
            return new GraphTransport(
                tenantId: config('services.graph.tenant_id'),
                clientId: config('services.graph.client_id'),
                clientSecret: config('services.graph.client_secret'),
                fromAddress: config('mail.from.address'),
                userId: config('services.graph.user_id'),
                graphEndpoint: config('services.graph.endpoint'),
                dispatcher: app('events'),
                logger: app('log')->channel()
            );
        });

        Event::listen(MessageSent::class, CopyMailToSentFolder::class);

        Inertia::share('company', function () {
            $company = Company::where('is_main', true)->first();
            if (!$company) {
                return null;
            }
            $logo_url = $company->logo_path ? asset('storage/' . $company->logo_path) : null;

            return [
                'name' => $company->name,
                'logo_url' => $logo_url,
            ];
        });
    }
}
