<?php

namespace App\Domain\Integrations\Sync;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Contracts\ReceivesSalesDocuments;
use App\Domain\Integrations\Data\CustomerData;
use App\Domain\Integrations\Data\PushedDocument;
use App\Domain\Integrations\Data\SalesDocumentData;
use App\Domain\Integrations\Data\SalesLineData;
use App\Domain\Integrations\DocumentForm;
use App\Domain\Signals\Integrations\ServiceOrderSentToAdministration;
use App\Domain\Signals\Signals;
use App\Exceptions\Refusal;
use App\Models\Customer;
use App\Models\Integrationable;
use App\Models\IntegrationConnection;
use App\Models\IntegrationRun;
use App\Models\ServiceOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Send to the administration" on a werkbon: to every connection that takes
 * werkbonnen, each in the form chosen for it. A werkbon is only marked sent
 * once every one of them has it; one that fails can be sent again, and the
 * connections that already have it are skipped then.
 *
 * Synchronous on purpose: whoever presses the button reads straight away what
 * went across, and why not when it did not.
 */
class ServiceOrderSender
{
    /** Materials carry no VAT rate of their own; this is what every send has used. */
    public const VAT_PERCENT = 21.0;

    /** Longer than any send takes; the lock goes as soon as the send is done. */
    private const LOCK_SECONDS = 300;

    public function __construct(private Synchronizer $synchronizer) {}

    /**
     * One send per werkbon at a time, and the checks made again once it has
     * the lock: a second press while the first still waits on the other side
     * would otherwise send it twice -- two invoices booked for one werkbon.
     *
     * @return array<int, array{system: string, form: DocumentForm, document: PushedDocument}>
     */
    public function send(ServiceOrder $order): array
    {
        $lock = Cache::lock('serviceorder-send:' . $order->getKey(), self::LOCK_SECONDS);

        if (!$lock->get()) {
            throw new Refusal('Deze werkbon wordt al verstuurd. Wacht even en kijk dan of het gelukt is.');
        }

        try {
            return $this->sendNow($order->refresh());
        } finally {
            $lock->release();
        }
    }

    /** @return array<int, array{system: string, form: DocumentForm, document: PushedDocument}> */
    private function sendNow(ServiceOrder $order): array
    {
        $connections = IntegrationConnection::writing(Area::ServiceOrders);

        if ($connections->isEmpty()) {
            throw new Refusal('Er is geen koppeling die werkbonnen ontvangt. Stel er een in onder Instellingen → Koppelingen.');
        }

        if ($order->sent_to_administration) {
            throw new Refusal('Deze werkbon is al naar de administratie verzonden.');
        }

        $order->load([
            'customer.billingCustomer',
            'materials',
            'taskInstances.serviceOrderTask',
            'taskInstances.materials',
            'serviceOrderStage',
        ]);

        if (!$order->is_closed) {
            throw new Refusal('Sluit de werkbon af voordat je hem naar de administratie verstuurt.');
        }

        $materials = $order->allMaterials();

        if ($materials->isEmpty()) {
            throw new Refusal('Geen materialen gekoppeld aan deze werkbon.');
        }

        $customer = $order->customer?->billingCustomer ?: $order->customer;

        if (!$customer) {
            throw new Refusal('Deze werkbon heeft geen klant.');
        }

        $results = [];

        foreach ($connections as $connection) {
            if ($order->integrationLink($connection)) {
                continue;
            }

            $results[] = $this->sendTo($connection, $order, $customer, $materials);
        }

        $order->sent_to_administration = true;
        $order->save();

        return $results;
    }

    /** @return array{system: string, form: DocumentForm, document: PushedDocument} */
    private function sendTo(IntegrationConnection $connection, ServiceOrder $order, Customer $customer, Collection $materials): array
    {
        $form = $connection->documentForm();
        $run = IntegrationRun::start($connection, Area::ServiceOrders, IntegrationRun::PUSH, $order);

        try {
            /** @var ReceivesSalesDocuments $connector */
            $connector = $connection->connector();
            $customer_id = $this->synchronizer->ensureLinked($connection, Area::Customers, $customer)
                ?? throw new Refusal($this->unlinked($connection, $customer));

            $data = $this->document($connection, $order, $customer, $customer_id, $materials);
            $document = $connector->pushSalesDocument($data, $form);
            $document->warnings = [...$data->warnings, ...$document->warnings];
        } catch (Throwable $e) {
            $run->fail($e);

            throw $e;
        }

        Integrationable::create([
            'integration_connection_id' => $connection->id,
            'integrationable_type' => $order->getMorphClass(),
            'integrationable_id' => $order->getKey(),
            'external_id' => $document->id,
            'confirmed_at' => now(),
            'synced_at' => now(),
        ]);

        $run->finish(['sent' => 1], $document->warnings === [] ? null : implode(' | ', $document->warnings));
        $connection->noteSuccess();

        Signals::dispatch(new ServiceOrderSentToAdministration(
            $order,
            $connection->connector,
            $connection->label(),
            $form,
            $document->id,
            $document->number,
        ));

        return ['system' => $connection->label(), 'form' => $form, 'document' => $document];
    }

    private function unlinked(IntegrationConnection $connection, Customer $customer): string
    {
        if ($customer->integrationLink($connection)?->missing_at) {
            return 'Klant ' . $customer->name . ' bestaat niet meer in ' . $connection->label() . '. '
                . 'Koppel hem daar opnieuw of maak hem daar opnieuw aan, en haal de klanten op.';
        }

        return 'Klant ' . $customer->name . ' is niet gekoppeld aan ' . $connection->label() . '. '
            . 'Haal eerst de klanten op, of laat Lavoro klanten naar ' . $connection->label() . ' sturen.';
    }

    private function document(
        IntegrationConnection $connection,
        ServiceOrder $order,
        Customer $customer,
        string $customer_id,
        Collection $materials,
    ): SalesDocumentData {
        $lines = [];
        $warnings = [];

        foreach ($materials as $material) {
            if (is_null($material->pivot->quantity)) {
                $warnings[] = 'Materiaal zonder hoeveelheid overgeslagen: ' . $material->name;

                continue;
            }

            $lines[] = new SalesLineData(
                description: (string) $material->name . ($material->task_instance
                    ? ' (gebruikt voor ' . ($material->task_instance->effective_title ?: 'taak zonder titel') . ')'
                    : ''),
                quantity: (float) $material->pivot->quantity,
                unit_price: (float) ($material->price ?? 0),
                vat_percent: self::VAT_PERCENT,
                article_id: $material->externalId($connection),
                code: $material->code,
            );
        }

        return new SalesDocumentData(
            reference: 'Werkbon ' . $order->id,
            description: 'Werkbon ' . $order->id,
            date: CarbonImmutable::today(),
            customer_id: $customer_id,
            customer: CustomerData::fromCustomer($customer),
            lines: $lines,
            memo: $order->external_purchaseorder_no,
            warnings: $warnings,
        );
    }
}
