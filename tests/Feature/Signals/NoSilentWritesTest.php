<?php

namespace Tests\Feature\Signals;

use Tests\TestCase;

/**
 * The automatic trail hears an Eloquent model being saved. It does not hear a
 * relation being attached, detached or synced, a query-builder update or delete, a
 * DB::table() write, or anything done quietly or without events. A change made that
 * way leaves no history unless someone raises a signal for it, and forgetting fails
 * in silence: a missing entry looks exactly like nothing having happened.
 *
 * So every such write in the application has to be accounted for: the method it
 * is in raises a signal, or it is listed below with the reason it needs none (a
 * technical table, or the caller raises the signal). A new one fails here until it
 * is one of the two.
 */
class NoSilentWritesTest extends TestCase
{
    /** Code that never touches a customer's records, or reaches them only through code that is checked. */
    private const NOT_SCANNED = [
        'app/Console/',
        'app/Domain/Integrations/Connectors/',
        'app/Domain/Signals/',
        'app/Http/Controllers/Landlord/',
        'app/Http/Middleware/',
        'app/Mail/',
        'app/Models/Central/',
        'app/Services/Push/',
        'app/Services/Tenancy/',
        'app/Tenancy/',
        'app/Listeners/RecordActivity.php',
    ];

    /**
     * Writes that need no signal of their own, by file and method, with why.
     *
     * @var array<string, string>
     */
    private const ACCOUNTED_FOR = [
        'app/Actions/Appointments/UpdateAppointmentAction.php::link' => 'announceMove() in the same class raises AppointmentServiceOrderChanged and ServiceOrderScheduled/Unscheduled',
        'app/Actions/Appointments/UpdateAppointmentAction.php::unlink' => 'announceMove() in the same class raises AppointmentServiceOrderChanged and ServiceOrderUnscheduled',
        'app/Actions/Products/ChangeProductAttributeFormatAction.php::relabel' => 'part of handle(), which raises ProductAttributeFormatChanged with the values folded and the products holding them',
        'app/Actions/Projects/SaveProjectTemplate.php::execute' => 'project templates are admin setup without a timeline; projects made from one keep their own milestones',
        'app/Actions/Quotes/ConvertQuoteToServiceOrderAction.php::book' => 'calls MateriableService::attach (a service, not a relation), which raises MaterialAttachedToOrder',
        'app/Domain/Integrations/OAuth/OAuthTokens.php::accessToken' => 'stores refreshed OAuth tokens on the connection: credentials, which the trail must never show',
        'app/Domain/Integrations/Sync/Webhooks.php::remove' => 'webhook lookup row in the central database, not tenant data',
        'app/Domain/Quotes/QuoteAlternatives.php::choose' => 'every caller raises the signal: UpdateQuoteLineAction (QuoteLineChanged), AcceptQuoteAction (QuoteAccepted), tidy() via QuoteLineRemoved / QuoteAlternativeChanged',
        'app/Domain/Tools/Write/BookMaterialsTool.php::execute' => 'calls MateriableService::attach (a service, not a relation), which raises MaterialAttachedToOrder',
        'app/Domain/Tools/Write/CreateProductTool.php::execute' => 'calls SyncProductAttributeTypesAction::attach (an action, not a relation), which raises ProductAttributeProductTypesChanged',
        'app/Domain/Tray/Tray.php::items' => 'drops items from a person\'s own bakje whose record is gone or out of sight: a personal clipboard',
        'app/Http/Controllers/Api/DeviceTokenController.php::destroy' => 'push device tokens: technical table',
        'app/Http/Controllers/Api/DeviceTokenController.php::upsert' => 'push device tokens: technical table',
        'app/Http/Controllers/Api/LocationPingController.php::store' => 'location pings: technical table, pruned after a day',
        'app/Http/Controllers/CatalogImportController.php::confirm' => 'atomic claim of an import run against a double confirm; the products the run writes carry their own history',
        'app/Http/Controllers/ProductAttributeProductTypeController.php::destroy' => 'calls SyncProductAttributeTypesAction::detach, which raises ProductAttributeProductTypesChanged',
        'app/Http/Controllers/ProductAttributeProductTypeController.php::store' => 'calls SyncProductAttributeTypesAction::attach, which raises ProductAttributeProductTypesChanged',
        'app/Http/Controllers/PushSubscriptionController.php::destroy' => 'web push subscriptions: technical table',
        'app/Http/Controllers/ServiceCheckController.php::store' => 'product types of a keurpunt template: admin setup without a timeline',
        'app/Http/Controllers/ServiceCheckController.php::update' => 'product types of a keurpunt template: admin setup without a timeline',
        'app/Http/Controllers/ServiceCheckGroupController.php::store' => 'product types of a keurpuntgroep template: admin setup without a timeline',
        'app/Http/Controllers/ServiceCheckGroupController.php::update' => 'product types of a keurpuntgroep template: admin setup without a timeline',
        'app/Http/Controllers/ServiceCheckValueController.php::updateOrder' => 'display order of a keurpunt template\'s answer options: admin setup without a timeline',
        'app/Http/Controllers/ServiceOrderController.php::attachMaterial' => 'calls MateriableService::attach, which raises MaterialAttachedToOrder',
        'app/Http/Controllers/ServiceOrderController.php::detachMaterial' => 'calls MateriableService::detach, which raises MaterialDetachedFromOrder',
        'app/Http/Controllers/ServiceOrderTaskInstanceMaterialController.php::destroy' => 'calls MateriableService::detach, which raises MaterialDetachedFromOrder',
        'app/Http/Controllers/ServiceOrderTaskInstanceMaterialController.php::store' => 'calls MateriableService::attach, which raises MaterialAttachedToOrder',
        'app/Http/Controllers/TrayController.php::clear' => 'empties a person\'s own bakje: a personal clipboard',
        'app/Http/Controllers/UserNotificationController.php::acknowledgeAll' => 'marks a person\'s own notifications read: technical table',
        'app/Http/Controllers/UserPlanGroupController.php::reorder' => 'only sort_order, which UserPlanGroup\'s history ignores as noise',
        'app/Jobs/DeleteOrphanedImagesJob.php::deleteOrphanedLinksOf' => 'image links whose record no longer exists: nothing is left to tell',
        'app/Jobs/PruneLocationPingsJob.php::handle' => 'prunes location pings: technical table',
        'app/Listeners/Auth/ForgetDeviceToken.php::handle' => 'device token of a device that logged out: technical table',
        'app/Listeners/Integrations/ForgetLinksOfDeletedRecords.php::handle' => 'integration links of a record just deleted; the deletion is the ModelChanged it listens to',
        'app/Listeners/NotifySubscribers.php::dropSuperseded' => 'reminder bookkeeping: unread reminders a newer notification replaces',
        'app/Models/Contact.php::createFor' => 'the link is part of creating the contact, whose "created" entry already lands on this customer',
        'app/Models/IntegrationConnection.php::noteFailure' => 'last_error bookkeeping of a connection',
        'app/Models/IntegrationConnection.php::noteSuccess' => 'last_synced_at bookkeeping of a connection',
        'app/Models/InternalAnnouncement.php::acknowledgeFor' => 'its caller, InternalAnnouncementController::acknowledge, raises AnnouncementAcknowledged when it returns true',
        'app/Models/PriceFormula.php::booted' => 'removes the deleted formula\'s own activityables, as a deleted record\'s pivot rows go with it',
        'app/Models/ProductAttribute.php::booted' => 'product type links of a kenmerk being deleted; its own "deleted" entry tells the removal',
        'app/Models/Quote.php::booted' => 'removes the deleted quote\'s own activityables; its "deleted" entry lands on the customer and project',
        'app/Models/ServiceOrderTaskChecklist.php::booted' => 'check links of a checklist template being deleted: admin setup without a timeline',
        'app/Models/ServiceOrderTaskInstance.php::booted' => 'flex part quantities of a task being deleted; the task\'s "deleted" entry on the werkbon tells it',
        'app/Models/ServiceOrderTask.php::booted' => 'check and checklist links of a task template being deleted: admin setup without a timeline',
        'app/Models/Traits/HasExecutingUsers.php::addExecutingUser' => 'only demo seeders and tests call it; the application plans people through syncExecutingUsers',
        'app/Models/Traits/HasExecutingUsers.php::syncExecutingUserRoles' => 'only called from syncExecutingUsers, whose before/after snapshot includes the plan roles',
        'app/Models/Traits/HasExecutingUsers.php::syncExecutingUsers' => 'executingUsersChanged() hands the crew before and after to Event, which raises AppointmentCrewChanged, also on its werkbonnen',
        'app/Models/Traits/HasOwner.php::bootHasOwner' => 'the creator becomes owner as the record is created; its "created" entry names the same person',
        'app/Models/Traits/Shareable.php::bootShareable' => 'share rows of a record just deleted, whose deletion has its own entry',
        'app/Services/AppointmentReminders.php::acknowledgePast' => 'reminder notifications: technical table',
        'app/Services/AppointmentReminders.php::tidy' => 'reminder claims: technical table',
        'app/Services/AppointmentReminders.php::withdrawOutdated' => 'reminder notifications: technical table',
        'app/Services/CustomerUploadIntake.php::receiveDocument' => 'its caller, CustomerUploadController::store, raises CustomerInfoUploaded once for the whole submission',
        'app/Services/CustomerUploadIntake.php::receiveImage' => 'its caller, CustomerUploadController::store, raises CustomerInfoUploaded once for the whole submission',
        'app/Services/CustomerUploadIntake.php::receiveNote' => 'its caller, CustomerUploadController::store, raises CustomerInfoUploaded once for the whole submission',
        'app/Services/MateriableService.php::attach' => 'announce() raises MaterialAttachedToOrder on the werkbon',
        'app/Services/MateriableService.php::release' => 'stock coming back raises MaterialStockRestored; lines are announced for a task deleted alone, a whole werkbon by its deletion',
        'app/Services/TaskChecks/TaskCheckRows.php::write' => 'check copies laid out as a task is made or edited, told by the task\'s entry or TaskExtraChecksChanged',
        'app/Services/TaskChecks/TaskCheckUnits.php::linkMachines' => 'ties check copies to machines after TaskAssetsRegistered or a task edit; the registration is the fact',
        'app/Services/TaskChecks/TaskCheckUnits.php::relayout' => 'follows the task\'s "Checks per machine" change, which its history tells; only while nothing is ticked',
        'app/Services/TaskChecks/TaskCheckUnits.php::sync' => 'follows the task\'s "Aantal" change, which its history tells; only unticked units without a machine go',
        'app/Services/TaskChecks/TaskInstanceChecks.php::followEdit' => 'calls TaskCheckUnits::sync and TaskCheckExtras::sync (services, not relations), both checked here',
        'app/Services/TenantProvisioner.php::create' => 'provisioning: the first admin of a new tenant, before it has any history',
        'app/Models/Asset.php::booted' => 'removes the deleted machine\'s own timeline rows, as Quote and MaintenanceContract do; its removal lands on the customer',
        'app/Services/TenantSuperAdmins.php::adopt' => 'super admin role, which must never appear in a customer\'s trail; also credits that person\'s earlier entries to MajorLabel',
        'app/Services/TenantSuperAdmins.php::copyIntoTheTenant' => 'super admin role, which must never appear in a customer\'s trail',
    ];

    /**
     * A chain that reads models first (get, first, each, ...) writes through each
     * model, which the trail hears; an Http:: chain attaches a file to a request.
     */
    private const PATTERNS = [
        'a relation write' => '/^(?!.*\bHttp::).*->(attach|detach|sync|syncWithoutDetaching|syncWithPivotValues|toggle|updateExistingPivot)\(/',
        'a query-builder write' => '/\b(?!DB::)[A-Z]\w*::(insert|insertOrIgnore|insertGetId|upsert|updateOrInsert|truncate)\(|(\b[A-Z]\w*::(query|where\w*|whereKey)\(|->(?!(get|first|firstOrFail|sole|pluck|exists|count|each|map|fresh|refresh)\()\w+\(\)\s*(?=->))(?!.*->(get|first|firstOrFail|sole|pluck|exists|count|each|map|fresh|refresh)\().*->(update|delete|increment|decrement|forceDelete|upsert|insert)\(/',
        'a DB::table() write' => '/DB::table\(.*->(update|insert|delete|upsert|increment|decrement|insertOrIgnore|updateOrInsert)\(/',
        'a write without events' => '/(saveQuietly|updateQuietly|deleteQuietly|createQuietly|withoutEvents)\(/',
    ];

    public function test_every_write_that_bypasses_the_trail_is_accounted_for(): void
    {
        $unaccounted = [];
        $needed = [];

        foreach ($this->sourceFiles() as $path) {
            foreach ($this->silentWrites($path) as [$method, $kind, $statement, $signalled]) {
                $key = $path . '::' . $method;

                if ($signalled) {
                    continue;
                }

                $needed[$key] = true;

                if (isset(self::ACCOUNTED_FOR[$key])) {
                    continue;
                }

                $unaccounted[] = $key . ' does ' . $kind . ' and raises no signal: ' . $statement;
            }
        }

        $this->assertSame(
            [],
            $unaccounted,
            "\nThese writes leave no history. Save through Eloquent, raise a signal in the same method, or add the"
                . " method to ACCOUNTED_FOR with the reason it needs none:\n" . implode("\n", $unaccounted) . "\n"
        );

        $stale = array_keys(array_diff_key(self::ACCOUNTED_FOR, $needed));

        $this->assertSame([], $stale, "\nThese entries in ACCOUNTED_FOR no longer excuse anything (the write is gone,"
            . " or its method now raises a signal); remove them:\n"
            . implode("\n", $stale) . "\n");
    }

    /** @return array<int, string> */
    private function sourceFiles(): array
    {
        $files = [];
        $base = base_path() . DIRECTORY_SEPARATOR;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace([$base, DIRECTORY_SEPARATOR], ['', '/'], $file->getPathname());

            foreach (self::NOT_SCANNED as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    continue 2;
                }
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * Each statement is read as one line, so a chain spread over several lines is
     * matched as a whole. A write counts as signalled when the method it is in
     * raises a signal itself.
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: bool}>
     */
    private function silentWrites(string $path): array
    {
        $source = file_get_contents(base_path($path));
        preg_match_all('/function\s+(\w+)\s*\(/', $source, $functions, PREG_OFFSET_CAPTURE);
        preg_match_all('/[^;{}]+;/', $source, $statements, PREG_OFFSET_CAPTURE);

        $writes = [];

        foreach ($statements[0] as [$statement, $offset]) {
            $line = preg_replace('/\s+/', ' ', trim($statement));

            foreach (self::PATTERNS as $kind => $pattern) {
                if (!preg_match($pattern, $line)) {
                    continue;
                }

                [$method, $body] = $this->enclosingMethod($source, $functions[1], $offset);
                $writes[] = [$method, $kind, mb_strimwidth($line, 0, 140, '...'), str_contains($body, 'Signals::dispatch(')];

                break;
            }
        }

        return $writes;
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $functions
     * @return array{0: string, 1: string}
     */
    private function enclosingMethod(string $source, array $functions, int $offset): array
    {
        $start = 0;
        $name = '(file)';
        $end = strlen($source);

        foreach ($functions as $index => [$function, $position]) {
            if ($position > $offset) {
                $end = $position;

                break;
            }

            $start = $position;
            $name = $function;
            $end = $functions[$index + 1][1] ?? strlen($source);
        }

        return [$name, substr($source, $start, $end - $start)];
    }
}
