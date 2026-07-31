<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/**
 * Factureren gebeurt na sluiten, dus de gefactureerde fase hoort in de volgorde na
 * de gesloten fase te staan. Het omzetten van een vlag en het verslepen van de rijen
 * komen bij verschillende requests binnen; ze rekenen ieder hun eigen invoer uit en
 * leggen de uitkomst hier naast dezelfde regel.
 */
trait ChecksStageOrdering
{
    protected function failWhenInvoicedPrecedesClosed(
        Validator $validator,
        string $attribute,
        ?int $closed_order,
        ?int $invoiced_order
    ): void {
        if ($closed_order === null || $invoiced_order === null || $invoiced_order > $closed_order) {
            return;
        }

        $validator->errors()->add(
            $attribute,
            'De gefactureerde fase moet in de volgorde na de gesloten fase komen.'
        );
    }
}
