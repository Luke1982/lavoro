<?php

namespace App\Domain\Integrations;

/**
 * What a werkbon becomes on the other side. A connector says which of these it
 * can make; the connection chooses one.
 */
enum DocumentForm: string
{
    case SalesOrder = 'sales_order';
    case DraftInvoice = 'draft_invoice';
    case FinalInvoice = 'final_invoice';

    public function label(): string
    {
        return match ($this) {
            self::SalesOrder => 'Verkooporder',
            self::DraftInvoice => 'Conceptfactuur',
            self::FinalInvoice => 'Definitieve factuur',
        };
    }
}
