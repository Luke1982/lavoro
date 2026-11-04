<?php

namespace App\Services\EBoekhouden;

use App\Exceptions\Refusal;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Which relation in e-Boekhouden a customer is.
 *
 * Linked by hand, after a search, and never created blindly: the same company
 * often is in the bookkeeping already -- from the CRM, or added by hand -- and
 * a second relation for it splits its invoices and payments over two.
 */
class RelationLinker
{
    public function __construct(private EBoekhoudenClient $client) {}

    /** @return array<int, array<string, mixed>> */
    public function search(string $term): array
    {
        return $this->client->searchRelations($term);
    }

    public function link(Tenant $tenant, string $code): array
    {
        $relation = $this->client->findRelation($code)
            ?? throw new Refusal("Relatie {$code} bestaat niet in e-Boekhouden.");

        $tenant->forceFill(['eboekhouden_relation_code' => $relation['code']])->save();

        return $relation;
    }

    public function unlink(Tenant $tenant): void
    {
        $tenant->forceFill(['eboekhouden_relation_code' => null])->save();
    }

    /**
     * Creates a relation for the customer, unless one that is clearly the same
     * company is already there: the same chamber of commerce number, VAT number,
     * invoice address or name. A name that merely contains the search term is
     * no reason to refuse -- "Spee" is not "Speelgoed BV" -- those are for the
     * person searching to judge.
     */
    public function create(Tenant $tenant): array
    {
        if ($tenant->eboekhouden_relation_code) {
            throw new Refusal("{$tenant->name} is al gekoppeld aan relatie {$tenant->eboekhouden_relation_code}.");
        }

        if ($same = $this->sameCompany($tenant)) {
            throw new Refusal("{$tenant->name} staat al in e-Boekhouden: " . collect($same)
                ->map(fn (array $match) => "{$match['code']} {$match['name']} ({$match['because']})")
                ->implode(', ') . '. Koppel die relatie in plaats van een nieuwe aan te maken.');
        }

        $code = $this->codeFor($tenant);

        if ($this->client->findRelation($code)) {
            throw new Refusal("De code {$code} is in e-Boekhouden al van een andere relatie. Koppel die, of pas hem daar aan.");
        }

        $this->client->addRelation([
            'code' => $code,
            'name' => $tenant->name,
            'address' => $tenant->invoice_address,
            'postcode' => $tenant->invoice_postcode,
            'city' => $tenant->invoice_city,
            'country' => $tenant->invoice_country,
            'email' => $tenant->invoice_email,
            'vat_number' => $tenant->vat_number,
            'coc_number' => $tenant->coc_number,
            'iban' => $tenant->iban,
        ]);

        $tenant->forceFill(['eboekhouden_relation_code' => $code])->save();

        return ['code' => $code, 'name' => $tenant->name];
    }

    /**
     * Relations that are the same company, each with the field that gave it
     * away.
     *
     * @return array<int, array{code: string, name: string, because: string}>
     */
    public function sameCompany(Tenant $tenant): array
    {
        $checks = array_filter([
            'KvK-nummer' => [$tenant->coc_number, 'coc_number', fn ($value) => preg_replace('/\D/', '', (string) $value)],
            'btw-nummer' => [$tenant->vat_number, 'vat_number', fn ($value) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value))],
            'e-mailadres' => [$tenant->invoice_email, 'email', fn ($value) => Str::lower(trim((string) $value))],
            'naam' => [$tenant->name, 'name', fn ($value) => $this->bareName((string) $value)],
        ], fn (array $check) => filled($check[0]) && filled($check[2]($check[0])));

        $found = $this->client->searchRelations(...array_map(fn (array $check) => (string) $check[0], array_values($checks)));
        $matches = [];

        foreach ($found as $relation) {
            foreach ($checks as $because => [$value, $field, $normal]) {
                if ($normal($relation[$field]) === $normal($value)) {
                    $matches[$relation['code']] ??= ['code' => $relation['code'], 'name' => $relation['name'], 'because' => $because];
                }
            }
        }

        return array_values($matches);
    }

    /**
     * The name without its legal form and punctuation: "Spee Totaaltechniek
     * B.V." and "spee totaaltechniek" are the same company.
     */
    private function bareName(string $name): string
    {
        return (string) Str::of($name)->lower()
            ->replaceMatches('/\b(b\.?\s?v\.?|v\.?\s?o\.?\s?f\.?|n\.?\s?v\.?)(?=\s|$)/u', '')
            ->replaceMatches('/[^\p{L}\p{N}]/u', '');
    }

    /**
     * A code that says where the relation came from and cannot collide with
     * the CRM's account numbers. From the customer's id, so it is the same
     * every time it is worked out.
     */
    public function codeFor(Tenant $tenant): string
    {
        return 'LVR-' . Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $tenant->id), 0, 8));
    }
}
