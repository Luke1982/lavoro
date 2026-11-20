<?php

namespace Tests\Fakes;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\ChangeDetection;
use App\Domain\Integrations\Connector;
use App\Domain\Integrations\Contracts\PullsCustomers;
use App\Domain\Integrations\Contracts\PullsMaterials;
use App\Domain\Integrations\Contracts\PushesCustomers;
use App\Domain\Integrations\Contracts\ReceivesSalesDocuments;
use App\Domain\Integrations\Contracts\ReceivesWebhooks;
use App\Domain\Integrations\Data\ChangeNotice;
use App\Domain\Integrations\Data\CustomerData;
use App\Domain\Integrations\Data\ExternalRecord;
use App\Domain\Integrations\Data\Field;
use App\Domain\Integrations\Data\MaterialData;
use App\Domain\Integrations\Data\PushedDocument;
use App\Domain\Integrations\Data\SalesDocumentData;
use App\Domain\Integrations\Data\WebhookSubscription;
use App\Domain\Integrations\DocumentForm;
use App\Domain\Integrations\RecordNotFound;
use App\Exceptions\Refusal;
use App\Models\IntegrationWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A system that can do everything, kept in memory: what the synchronizer does
 * can be checked without any one real API in between. Reset in setUp.
 */
class FakeConnector extends Connector implements PullsCustomers, PullsMaterials, PushesCustomers, ReceivesSalesDocuments, ReceivesWebhooks
{
    /** @var array<string, array<string, ?string>> Their customers, by id. */
    public static array $customers = [];

    /** @var array<string, array<string, mixed>> Their articles, by id. */
    public static array $materials = [];

    /** @var array<int, array{form: DocumentForm, document: SalesDocumentData}> */
    public static array $documents = [];

    /** @var array<int, string> Every call that changed something there. */
    public static array $calls = [];

    /** @var array<int, string> */
    public static array $subscriptions = [];

    public static bool $refuse = false;

    /** Delta makes this fake one that can say what changed since a moment. */
    public static ChangeDetection $detection = ChangeDetection::Full;

    /** @var array<int, ?CarbonImmutable> The "since" of every customer pull. */
    public static array $pulls = [];

    /** @var array<string, CarbonImmutable> When each customer there last changed. */
    public static array $changed_at = [];

    private static int $next_id = 1000;

    public static function reset(): void
    {
        self::$customers = [];
        self::$materials = [];
        self::$documents = [];
        self::$calls = [];
        self::$subscriptions = [];
        self::$refuse = false;
        self::$detection = ChangeDetection::Full;
        self::$pulls = [];
        self::$changed_at = [];
        self::$next_id = 1000;
    }

    public static function key(): string
    {
        return 'fake';
    }

    public static function label(): string
    {
        return 'Nepboekhouding';
    }

    public static function description(): string
    {
        return 'Alleen voor tests.';
    }

    public static function credentialFields(): array
    {
        return [new Field('api_key', 'API-sleutel', secret: true)];
    }

    public function test(): void
    {
        if ($this->credential('api_key') !== 'goed') {
            throw new Refusal('Nepboekhouding weigert de sleutel.');
        }
    }

    public static function customerChanges(): ChangeDetection
    {
        return self::$detection;
    }

    public static function materialChanges(): ChangeDetection
    {
        return ChangeDetection::Full;
    }

    public static function documentForms(): array
    {
        return [DocumentForm::SalesOrder, DocumentForm::FinalInvoice];
    }

    public static function webhookAreas(): array
    {
        return [Area::Customers];
    }

    public function webhookScope(): string
    {
        return 'nep';
    }

    public function pullCustomers(?CarbonImmutable $since = null): iterable
    {
        self::$pulls[] = $since;

        foreach (self::$customers as $id => $values) {
            if ($since && (self::$changed_at[$id] ?? $since)->lessThanOrEqualTo($since)) {
                continue;
            }

            yield new ExternalRecord((string) $id, CustomerData::from($values));
        }
    }

    public function fetchCustomer(string $id): ?ExternalRecord
    {
        return isset(self::$customers[$id]) ? new ExternalRecord($id, CustomerData::from(self::$customers[$id])) : null;
    }

    public function createCustomer(CustomerData $customer): ExternalRecord
    {
        $id = (string) self::$next_id++;
        self::$customers[$id] = $customer->only(CustomerData::FIELDS);
        self::$calls[] = 'create:' . $id;

        return $this->fetchCustomer($id);
    }

    public function updateCustomer(string $id, CustomerData $customer): ExternalRecord
    {
        if (!isset(self::$customers[$id])) {
            throw new RecordNotFound('Nepboekhouding kent ' . $id . ' niet.');
        }

        self::$customers[$id] = $customer->only(CustomerData::FIELDS);
        self::$calls[] = 'update:' . $id;

        return $this->fetchCustomer($id);
    }

    public function pullMaterials(?CarbonImmutable $since = null): iterable
    {
        foreach (self::$materials as $id => $values) {
            yield new ExternalRecord((string) $id, MaterialData::from($values));
        }
    }

    public function fetchMaterial(string $id): ?ExternalRecord
    {
        return isset(self::$materials[$id]) ? new ExternalRecord($id, MaterialData::from(self::$materials[$id])) : null;
    }

    public function pushSalesDocument(SalesDocumentData $document, DocumentForm $form): PushedDocument
    {
        if (self::$refuse) {
            throw new Refusal('Nepboekhouding weigert deze werkbon.');
        }

        self::$documents[] = ['form' => $form, 'document' => $document];
        $id = (string) self::$next_id++;

        return new PushedDocument($id, $form === DocumentForm::FinalInvoice ? 'F-' . $id : null);
    }

    public function subscribe(Area $area, string $url, IntegrationWebhook $webhook): WebhookSubscription
    {
        self::$subscriptions[] = $url;

        return new WebhookSubscription('sub-' . $area->value, CarbonImmutable::now()->addDays(3));
    }

    public function unsubscribe(IntegrationWebhook $webhook): void
    {
        self::$calls[] = 'unsubscribe:' . $webhook->external_id;
    }

    public function handshake(Request $request, IntegrationWebhook $webhook): ?Response
    {
        return $request->has('validationToken') ? response($request->input('validationToken'), 200) : null;
    }

    public function notices(Request $request, IntegrationWebhook $webhook): array
    {
        return array_map(fn (string $id) => new ChangeNotice(Area::Customers, $id), (array) $request->input('changed', []));
    }
}
