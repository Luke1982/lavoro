<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Data\PushedDocument;
use App\Domain\Integrations\Data\SalesDocumentData;
use App\Domain\Integrations\DocumentForm;

/**
 * The other side takes a werkbon, as one of the forms it lists.
 */
interface ReceivesSalesDocuments
{
    /** @return array<int, DocumentForm> */
    public static function documentForms(): array;

    /**
     * Throws a Refusal when the document cannot go as it is -- no line the
     * other side can take, a missing setting -- so the person who pressed the
     * button reads why.
     */
    public function pushSalesDocument(SalesDocumentData $document, DocumentForm $form): PushedDocument;
}
