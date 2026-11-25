<?php

namespace App\Domain\Assistant\Reading;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\CalendarGrant;
use App\Models\CatalogImport;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomField;
use App\Models\DayRoute;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Event;
use App\Models\EventType;
use App\Models\FreeformMaterial;
use App\Models\GeneralSetting;
use App\Models\Image;
use App\Models\ImportMapping;
use App\Models\IntegrationConnection;
use App\Models\InternalAnnouncement;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\MaintenanceContract;
use App\Models\MaintenanceContractTemplate;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialUsageUnit;
use App\Models\NotificationMute;
use App\Models\NotificationSubscription;
use App\Models\PriceFormula;
use App\Models\Product;
use App\Models\Productable;
use App\Models\ProductAttribute;
use App\Models\ProductRelation;
use App\Models\ProductType;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTemplate;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\QuoteLostReason;
use App\Models\QuoteTemplate;
use App\Models\QuoteUnit;
use App\Models\QuoteVersion;
use App\Models\Remark;
use App\Models\Role;
use App\Models\ServiceCheck;
use App\Models\ServiceCheckGroup;
use App\Models\ServiceCheckInstance;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderMacro;
use App\Models\ServiceOrderStage;
use App\Models\ServiceOrderTask;
use App\Models\ServiceOrderTaskCheck;
use App\Models\ServiceOrderTaskInstance;
use App\Models\ServiceOrderTaskInstanceCheck;
use App\Models\StandardAttachment;
use App\Models\StandardEmail;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\TourProgress;
use App\Models\TrayItem;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserPlanGroup;
use App\Models\UserRole;
use App\Models\UserUnavailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The kinds of record the assistant can read, and the one Dutch word for each.
 *
 * Readable is every kind in NOUNS whose policy answers viewAny, minus the ones
 * in UNREADABLE with the reason they stay out; who may read which rows is the
 * policy's and the scope's business, never this class's. A kind with a
 * visibility scope is open to anyone and the scope decides the rows, since view
 * is not always within viewAny (a monteur opens the werkbon he is assigned to);
 * viewAny alone gates a kind without one. A policy written next
 * month needs two things here: its noun in NOUNS, or a line in UNREADABLE with
 * the reason. ReadLayerCoverageTest fails for a model with a policy that has
 * neither, so a new policy cannot quietly stay out of (or slip into) the
 * assistant.
 *
 * The nouns are shared by everything in the assistant that names a record: the
 * history search, the page someone is looking at, the read tool. One word per
 * kind, so a werkbon the page context mentions is a werkbon the tools accept.
 *
 * See docs/development/assistant-read-layer.md.
 */
final class RecordKinds
{
    /** @var array<class-string<Model>, string> */
    public const NOUNS = [
        ServiceOrder::class => 'werkbon',
        Ticket::class => 'storing',
        Asset::class => 'machine',
        Event::class => 'afspraak',
        Customer::class => 'klant',
        MaintenanceContract::class => 'onderhoudscontract',
        Project::class => 'project',
        PriceFormula::class => 'prijsformule',
        Quote::class => 'offerte',
        Product::class => 'product',
        ProductAttribute::class => 'kenmerk',
        Material::class => 'materiaal',
        LedgerAccount::class => 'grootboekrekening',
        IntegrationConnection::class => 'koppeling',
        Supplier::class => 'leverancier',
        MaintenanceContractTemplate::class => 'contractsjabloon',
        ServiceOrderStage::class => 'fase',
        User::class => 'medewerker',
        Role::class => 'rol',
        StandardEmail::class => 'standaardmail',
        StandardAttachment::class => 'standaardbijlage',
        UserPlanGroup::class => 'plangroep',
        UserRole::class => 'planrol',
        EventType::class => 'afspraaktype',
        Company::class => 'bedrijf',
        GeneralSetting::class => 'instelling',
        Contact::class => 'contactpersoon',
        Location::class => 'locatie',
        Brand::class => 'merk',
        ProductType::class => 'productsoort',
        Document::class => 'document',
        DocumentCategory::class => 'documentcategorie',
        Image::class => 'foto',
        Remark::class => 'opmerking',
        InternalAnnouncement::class => 'aankondiging',
        DayRoute::class => 'dagroute',
        FreeformMaterial::class => 'materiaalregel',
        MaterialUsageUnit::class => 'eenheid',
        ProjectMilestone::class => 'mijlpaal',
        ProjectTemplate::class => 'projectsjabloon',
        ServiceJob::class => 'keuring',
        ServiceCheck::class => 'keurpunt',
        ServiceCheckGroup::class => 'keurpuntgroep',
        ServiceCheckInstance::class => 'keuringsantwoord',
        ServiceOrderMacro::class => 'macro',
        ServiceOrderTask::class => 'taaksjabloon',
        ServiceOrderTaskInstance::class => 'taak',
        ServiceOrderTaskCheck::class => 'controlepunt',
        ServiceOrderTaskInstanceCheck::class => 'taakcontrole',
        UserUnavailability::class => 'afwezigheid',
        UserNotification::class => 'melding',
        CustomField::class => 'extra veld',
        MaterialCategory::class => 'materiaalcategorie',
        QuoteLine::class => 'offerteregel',
        QuoteVersion::class => 'offerteversie',
        QuoteLostReason::class => 'verliesreden',
        QuoteTemplate::class => 'offertesjabloon',
        QuoteUnit::class => 'offerte-eenheid',
    ];

    /**
     * The plural, for the sentence above a filtered list ("werkbonnen met ...").
     * Only the kinds that have an overview page need one.
     *
     * @var array<class-string<Model>, string>
     */
    public const PLURALS = [
        ServiceOrder::class => 'werkbonnen',
        Ticket::class => 'storingen',
        Asset::class => 'machines',
        Customer::class => 'klanten',
        Contact::class => 'contactpersonen',
        Location::class => 'locaties',
        MaintenanceContract::class => 'onderhoudscontracten',
        Product::class => 'producten',
        Project::class => 'projecten',
        Quote::class => 'offertes',
        Material::class => 'materialen',
        Supplier::class => 'leveranciers',
        User::class => 'medewerkers',
    ];

    /**
     * The pages the assistant knows, by the path they live at; the one list that
     * says which kind a page shows and what it does with it:
     *
     * - `kind`: the records the page is about;
     * - `screen`: how the page context names the list ("het klantenoverzicht");
     * - `record`: /<path>/<id> is one record's page;
     * - `filter`: the overview takes a one-time filter from the assistant
     *   (`?filter=<uuid>`). Only where its controller applies AssistantFilter;
     *   ReadLayerCoverageTest holds the two together.
     *
     * @var array<string, array{kind?: class-string<Model>, screen?: string, record?: bool, filter?: bool}>
     */
    public const SCREENS = [
        'planner' => ['screen' => 'de planning'],
        'serviceorders' => ['kind' => ServiceOrder::class, 'screen' => 'het overzicht van werkbonnen', 'record' => true, 'filter' => true],
        'tickets' => ['kind' => Ticket::class, 'screen' => 'het overzicht van storingen', 'record' => true, 'filter' => true],
        'assets' => ['kind' => Asset::class, 'screen' => 'het overzicht van machines', 'record' => true, 'filter' => true],
        'customers' => ['kind' => Customer::class, 'screen' => 'het klantenoverzicht', 'record' => true, 'filter' => true],
        'projects' => ['kind' => Project::class, 'screen' => 'het projectenoverzicht', 'record' => true, 'filter' => true],
        'products' => ['kind' => Product::class, 'screen' => 'het productenoverzicht', 'record' => true, 'filter' => true],
        'maintenancecontracts' => ['kind' => MaintenanceContract::class, 'screen' => 'het overzicht van onderhoudscontracten', 'record' => true, 'filter' => true],
        'priceformulas' => ['kind' => PriceFormula::class, 'screen' => 'het overzicht van prijsformules', 'record' => true],
        'quotes' => ['kind' => Quote::class, 'screen' => 'het overzicht van offertes', 'record' => true, 'filter' => true],
        'contacts' => ['kind' => Contact::class, 'filter' => true],
        'locations' => ['kind' => Location::class, 'filter' => true],
        'materials' => ['kind' => Material::class, 'filter' => true],
        'suppliers' => ['kind' => Supplier::class, 'filter' => true],
        'users' => ['kind' => User::class, 'filter' => true],
    ];

    /**
     * Models with a policy that the assistant does not read, and why.
     *
     * A reason has to be about the data, not about a missing scope: a kind that
     * should be readable but cannot be yet gets its policy fixed, not a line here.
     *
     * @var array<class-string<Model>, string>
     */
    public const UNREADABLE = [
        IntegrationConnection::class => 'Credentials and connection settings of a bookkeeping link; its history is searchable, its rows are not.',
        GeneralSetting::class => 'A key/value store that also holds the SMTP password and the Graph client secret.',
        CalendarGrant::class => 'Calendar sharing between colleagues: who may see whose agenda is a credential-like grant, not a business record.',
        CatalogImport::class => 'Technical: an uploaded spreadsheet on its way into the catalogue, with its file path and run state.',
        ImportMapping::class => 'Technical: saved column choices for spreadsheet imports.',
        NotificationMute::class => 'Personal notification settings of one user.',
        NotificationSubscription::class => 'Personal notification settings of one user.',
        UserNotification::class => 'One person\'s inbox: copies of facts that are already on the records\' own timelines.',
        TourProgress::class => 'How far one person got through a guided tour; nothing a question is about.',
        TrayItem::class => 'The personal tray: a clipboard of things someone is about to paste, not records of their own.',
        Productable::class => 'Technical pivot linking a product to a record; read through the product or the record.',
        ProductRelation::class => 'Technical link between two products; read through the product.',
        QuoteTemplate::class => 'A setting: the starting text and lines for new quotes, not a quote of its own.',
        QuoteUnit::class => 'A setting: the units a quote line can be counted in.',
    ];

    /** @var array<class-string<Model>, string>|null */
    private static ?array $readable = null;

    /**
     * Every kind the assistant may read, as class => noun: the nouns whose model
     * has a policy with viewAny and is not kept out in UNREADABLE.
     *
     * @return array<class-string<Model>, string>
     */
    public static function readable(): array
    {
        if (self::$readable !== null) {
            return self::$readable;
        }

        $readable = [];

        foreach (self::NOUNS as $class => $noun) {
            if (isset(self::UNREADABLE[$class])) {
                continue;
            }

            $policy = Gate::getPolicyFor($class);

            if ($policy !== null && method_exists($policy, 'viewAny')) {
                $readable[$class] = $noun;
            }
        }

        return self::$readable = $readable;
    }

    /**
     * The kinds to offer this person, as class => noun: the ones viewAny opens.
     * A kind they reach only through its scope (the werkbon a customer.read
     * monteur is assigned to) still reads when named, but listing every scoped
     * kind to everyone would offer kinds that answer nothing.
     *
     * @return array<class-string<Model>, string>
     */
    public static function readableBy(User $user): array
    {
        return array_filter(self::readable(), fn (string $noun, string $class) => $user->can('viewAny', $class), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Every model in app/Models that has a policy, registered or discovered.
     * For ReadLayerCoverageTest: it loads every model, which a request has no
     * reason to pay for.
     *
     * @return array<class-string<Model>, object>
     */
    public static function withPolicy(): array
    {
        $found = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');

            if (!class_exists($class) || !is_subclass_of($class, Model::class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $policy = Gate::getPolicyFor($class);

            if ($policy !== null) {
                $found[$class] = $policy;
            }
        }

        ksort($found);

        return $found;
    }

    /** @return class-string<Model>|null */
    public static function classFor(?string $noun): ?string
    {
        if ($noun === null) {
            return null;
        }

        $class = array_search(mb_strtolower(trim($noun)), self::NOUNS, true);

        return $class === false ? null : $class;
    }

    public static function noun(string $class): string
    {
        return self::NOUNS[$class] ?? class_basename($class);
    }

    public static function plural(string $class): string
    {
        return self::PLURALS[$class] ?? self::noun($class);
    }

    public static function isReadable(string $class): bool
    {
        return isset(self::readable()[$class]);
    }

    /**
     * Whether this person may read records of this kind at all; which ones is the
     * scope's business. Where the kind has a visibility scope that scope is the
     * whole rule, so whoever view lets open a record without viewAny (assigned to
     * a werkbon or an afspraak) reads it here as well; ReadLayerLeakTest holds
     * every scope to nothing for somebody without any rights.
     */
    public static function mayRead(string $class, User $user): bool
    {
        return self::isReadable($class) && (self::hasVisibilityScope($class) || $user->can('viewAny', $class));
    }

    /** Whether the model selects the records a person may see through scopeVisibleTo(). */
    public static function hasVisibilityScope(string $class): bool
    {
        return (new $class)->hasNamedScope('visibleTo');
    }

    /**
     * Whether the kind's visibility scope keeps any record of this kind from this
     * person, judged by whether it adds a condition at all: a scope that hands
     * back the query untouched means the right to every record, also the ones no
     * longer in the table.
     *
     * @param  class-string<Model>  $class
     */
    public static function narrowsFor(string $class, User $user): bool
    {
        if (!self::hasVisibilityScope($class)) {
            return false;
        }

        $query = $class::query();
        $before = count($query->getQuery()->wheres);
        $query->visibleTo($user);

        return count($query->getQuery()->wheres) !== $before || $query->getQuery()->joins !== null;
    }

    /**
     * Whether this person may open the kind's overview page: the policy's list
     * where the overview is stricter than reading, viewAny where it is not.
     */
    public static function mayList(string $class, User $user): bool
    {
        if (self::overview($class) === null || !self::isReadable($class)) {
            return false;
        }

        $policy = Gate::getPolicyFor($class);

        return $user->can($policy !== null && method_exists($policy, 'list') ? 'list' : 'viewAny', $class);
    }

    /**
     * The overviews that take a one-time filter, as class => path.
     *
     * @return array<class-string<Model>, string>
     */
    public static function overviews(): array
    {
        $overviews = [];

        foreach (self::SCREENS as $path => $screen) {
            if (($screen['filter'] ?? false) && isset($screen['kind'])) {
                $overviews[$screen['kind']] = $path;
            }
        }

        return $overviews;
    }

    /** The path of the kind's overview that takes a filter, like "/serviceorders", or null. */
    public static function overview(string $class): ?string
    {
        $path = self::overviews()[$class] ?? null;

        return $path === null ? null : '/' . $path;
    }

    /** Whether this path is an overview that takes a filter, of whichever kind. */
    public static function isOverview(string $path): bool
    {
        return in_array(trim($path, '/'), self::overviews(), true);
    }

    /**
     * The kinds with a page of their own at /<path>/<id>, as path => class.
     *
     * @return array<string, class-string<Model>>
     */
    public static function recordPages(): array
    {
        $pages = [];

        foreach (self::SCREENS as $path => $screen) {
            if (($screen['record'] ?? false) && isset($screen['kind'])) {
                $pages[$path] = $screen['kind'];
            }
        }

        return $pages;
    }

    /** How the page context names the list at this path, or null when it does not name it. */
    public static function screenName(string $path): ?string
    {
        return self::SCREENS[$path]['screen'] ?? null;
    }

    /** The path of one record's own page, or null when the kind has none the assistant links to. */
    public static function page(string $class, int $id): ?string
    {
        $path = array_search($class, self::recordPages(), true);

        return $path === false ? null : '/' . $path . '/' . $id;
    }

    /**
     * The records of this kind this person may see: the model's own visibility
     * scope where view depends on the record, every row where viewAny is the
     * whole rule.
     *
     * @param  class-string<Model>  $class
     */
    public static function query(string $class, User $user): Builder
    {
        return self::constrain($class::query(), $user);
    }

    /** The same narrowing, on a query somebody else built (a whereHas, an overview page). */
    public static function constrain(Builder $query, User $user): Builder
    {
        if (self::hasVisibilityScope($query->getModel()::class)) {
            $query->visibleTo($user);
        }

        return $query;
    }
}
