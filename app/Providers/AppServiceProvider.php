<?php

namespace App\Providers;

use App\Listeners\CopyMailToSentFolder;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Transport\Dsn;
use Illuminate\Support\Facades\Mail;
use App\Mail\Transports\GraphTransport;
use Inertia\Inertia;
use App\Models\Company;
use Illuminate\Support\Facades\Storage;
use App\Models\CalendarGrant;
use App\Models\Event as EventModel;
use App\Models\StandardAttachment;
use App\Models\StandardEmail;
use App\Models\UserUnavailability;
use App\Policies\CalendarGrantPolicy;
use App\Policies\EventPolicy;
use App\Policies\StandardAttachmentPolicy;
use App\Policies\StandardEmailPolicy;
use App\Policies\UserUnavailabilityPolicy;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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

        EventModel::observe(\App\Observers\EventObserver::class);
        \App\Models\Ticket::observe(\App\Observers\TicketObserver::class);

        Event::listen('eloquent.attached: App\Models\Event', function ($event_class, $payload) {
            [$model, $relation, $ids] = $payload + [null, null, []];
            if (!$model instanceof \App\Models\Event) {
                return;
            }
            \App\Jobs\Google\PushEventJob::dispatch($model->id);
        });

        Event::listen('eloquent.detached: App\Models\Event', function ($event_class, $payload) {
            [$model, $relation, $ids] = $payload + [null, null, []];
            if (!$model instanceof \App\Models\Event) {
                return;
            }
            \App\Jobs\Google\PushEventJob::dispatch($model->id);
            $event_id = $model->id;
            $still_relevant_user_ids = array_unique(array_merge(
                $model->owners()->wherePivot('type', 'owner')->pluck('users.id')->all(),
                $model->executingUsers()->pluck('users.id')->all(),
            ));
            $stale_mappings = \App\Models\GoogleSyncedEvent::whereHas(
                'syncedCalendar',
                fn ($q) => $q->whereNotIn('owner_user_id', $still_relevant_user_ids),
            )->where('event_id', $event_id)->get();
            foreach ($stale_mappings as $mapping) {
                \App\Jobs\Google\DeleteEventFromGoogleJob::dispatch(
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
