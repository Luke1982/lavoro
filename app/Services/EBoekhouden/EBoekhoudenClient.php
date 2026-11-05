<?php

namespace App\Services\EBoekhouden;

use App\Exceptions\Refusal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use XMLWriter;

/**
 * The SOAP service of e-Boekhouden.nl, spoken by hand.
 *
 * Not through PHP's SoapClient: that needs the soap extension, which is not on
 * every server, and it cannot be faked in a test. The envelopes are small, and
 * the one thing to get right is the order of the elements -- the service is a
 * .NET asmx and quietly ignores a field that comes out of order. Every payload
 * below is therefore written in the order of the WSDL.
 *
 * Every call opens a session and closes it again. There are a handful of calls
 * per click in the admin panel; keeping a session alive between requests is not
 * worth the state.
 */
class EBoekhoudenClient
{
    private const NS = 'http://www.e-boekhouden.nl/soap';

    private const ENVELOPE = 'http://schemas.xmlsoap.org/soap/envelope/';

    public function configured(): bool
    {
        return filled(config('services.eboekhouden.username'))
            && filled(config('services.eboekhouden.security_code_1'))
            && filled(config('services.eboekhouden.security_code_2'));
    }

    /**
     * Relations that match any of the search terms, each relation once.
     * e-Boekhouden searches a term in the name, the code and the other fields
     * of a relation. All terms in one session: the check for a duplicate asks
     * four at once.
     *
     * @return array<int, array{id: int, code: string, name: string, city: string, email: string, vat_number: string, coc_number: string}>
     */
    public function searchRelations(string ...$terms): array
    {
        return $this->session(fn (string $session) => collect($terms)
            ->filter(fn (string $term) => filled($term))
            ->unique()
            ->flatMap(fn (string $term) => $this->relations($session, ['Trefwoord' => $term, 'Code' => '', 'ID' => 0]))
            ->unique('code')
            ->values()
            ->all());
    }

    /** The relation with exactly this code, if there is one. */
    public function findRelation(string $code): ?array
    {
        $found = $this->session(fn (string $session) => $this->relations($session, ['Trefwoord' => '', 'Code' => $code, 'ID' => 0]));

        return collect($found)->first(fn (array $relation) => $relation['code'] === $code);
    }

    /**
     * Creates a company relation and returns its id in e-Boekhouden.
     *
     * @param  array{code: string, name: string, address: ?string, postcode: ?string, city: ?string, country: ?string, email: ?string, vat_number: ?string, coc_number: ?string, iban: ?string}  $relation
     */
    public function addRelation(array $relation): int
    {
        $result = $this->session(fn (string $session) => $this->call('AddRelatie', [
            'SessionID' => $session,
            'SecurityCode2' => config('services.eboekhouden.security_code_2'),
            'oRel' => [
                'ID' => 0,
                'AddDatum' => now()->format('Y-m-d'),
                'Code' => $relation['code'],
                'Bedrijf' => $relation['name'],
                'Contactpersoon' => '',
                'Geslacht' => '',
                'Adres' => (string) $relation['address'],
                'Postcode' => (string) $relation['postcode'],
                'Plaats' => (string) $relation['city'],
                'Land' => (string) ($relation['country'] ?: 'NL'),
                'Adres2' => '',
                'Postcode2' => '',
                'Plaats2' => '',
                'Land2' => '',
                'Telefoon' => '',
                'GSM' => '',
                'FAX' => '',
                'Email' => (string) $relation['email'],
                'Site' => '',
                'Notitie' => 'Lavoro',
                'Bankrekening' => '',
                'Girorekening' => '',
                'BTWNummer' => (string) $relation['vat_number'],
                'KvkNummer' => (string) $relation['coc_number'],
                'Aanhef' => '',
                'IBAN' => (string) $relation['iban'],
                'BIC' => '',
                'BP' => 'B',
                'Def1' => '', 'Def2' => '', 'Def3' => '', 'Def4' => '', 'Def5' => '',
                'Def6' => '', 'Def7' => '', 'Def8' => '', 'Def9' => '', 'Def10' => '',
                'LA' => '',
                'Gb_ID' => 0,
                'GeenEmail' => 1,
                'NieuwsbriefgroepenCount' => 0,
            ],
        ]));

        return (int) $result->Rel_ID;
    }

    /**
     * The relation codes of the bookings with this invoice number; none when
     * it is not there.
     *
     * @return array<int, string>
     */
    public function bookedRelations(string $number): array
    {
        return $this->session(function (string $session) use ($number) {
            $result = $this->call('GetMutaties', [
                'SessionID' => $session,
                'SecurityCode2' => config('services.eboekhouden.security_code_2'),
                'cFilter' => [
                    'MutatieNr' => 0,
                    'MutatieNrVan' => 0,
                    'MutatieNrTm' => 0,
                    'Factuurnummer' => $number,
                    'DatumVan' => '1990-01-01',
                    'DatumTm' => '2100-01-01',
                ],
            ]);

            return collect($this->items($result->Mutaties->cMutatieList))
                ->filter(fn (SimpleXMLElement $mutation) => (string) $mutation->Factuurnummer === $number)
                ->map(fn (SimpleXMLElement $mutation) => (string) $mutation->RelatieCode)
                ->unique()
                ->values()
                ->all();
        });
    }

    /**
     * Books a sent invoice and returns the number of the booking.
     *
     * @param  array{date: string, relation_code: string, number: string, description: string, payment_days: int, lines: array<int, array{excl: string, vat: string, incl: string, vat_code: string, vat_percent: string}>}  $invoice
     */
    public function addInvoice(array $invoice): string
    {
        $result = $this->session(fn (string $session) => $this->call('AddMutatie', [
            'SessionID' => $session,
            'SecurityCode2' => config('services.eboekhouden.security_code_2'),
            'oMut' => [
                'MutatieNr' => 0,
                'Soort' => 'FactuurVerstuurd',
                'Datum' => $invoice['date'],
                'Rekening' => config('services.eboekhouden.debtors_account'),
                'RelatieCode' => $invoice['relation_code'],
                'Factuurnummer' => $invoice['number'],
                'Boekstuk' => '',
                'Omschrijving' => $invoice['description'],
                'Betalingstermijn' => (string) $invoice['payment_days'],
                'Betalingskenmerk' => '',
                'InExBTW' => 'EX',
                'MutatieRegels' => array_map(fn (array $line) => ['cMutatieRegel' => [
                    'BedragInvoer' => $line['excl'],
                    'BedragExclBTW' => $line['excl'],
                    'BedragBTW' => $line['vat'],
                    'BedragInclBTW' => $line['incl'],
                    'BTWCode' => $line['vat_code'],
                    'BTWPercentage' => $line['vat_percent'],
                    'TegenrekeningCode' => config('services.eboekhouden.revenue_account'),
                    'KostenplaatsID' => 0,
                ]], $invoice['lines']),
            ],
        ]));

        return (string) $result->Mutatienummer;
    }

    /** @return array<int, array{id: int, code: string, name: string, city: string, email: string, vat_number: string, coc_number: string}> */
    private function relations(string $session, array $filter): array
    {
        $result = $this->call('GetRelaties', [
            'SessionID' => $session,
            'SecurityCode2' => config('services.eboekhouden.security_code_2'),
            'cFilter' => $filter,
        ]);

        return collect($this->items($result->Relaties->cRelatie))
            ->map(fn (SimpleXMLElement $relation) => [
                'id' => (int) $relation->ID,
                'code' => (string) $relation->Code,
                'name' => (string) $relation->Bedrijf,
                'city' => (string) $relation->Plaats,
                'email' => (string) $relation->Email,
                'vat_number' => (string) $relation->BTWNummer,
                'coc_number' => (string) $relation->KvkNummer,
            ])
            ->values()
            ->all();
    }

    /**
     * Opens a session, does the work and always closes it again -- also when
     * the work fails, or the sessions pile up on their side.
     *
     * @template T
     *
     * @param  callable(string): T  $work
     * @return T
     */
    private function session(callable $work): mixed
    {
        if (!$this->configured()) {
            throw new Refusal('e-Boekhouden is niet ingesteld: EBOEKHOUDEN_USERNAME en de twee beveiligingscodes ontbreken in .env.');
        }

        $session = (string) $this->call('OpenSession', [
            'Username' => config('services.eboekhouden.username'),
            'SecurityCode1' => config('services.eboekhouden.security_code_1'),
            'SecurityCode2' => config('services.eboekhouden.security_code_2'),
            'Source' => 'Lavoro',
        ])->SessionID;

        try {
            return $work($session);
        } finally {
            try {
                $this->call('CloseSession', ['SessionID' => $session]);
            } catch (Refusal) {
                /** The work is done; a session left open expires on their side. */
            }
        }
    }

    /**
     * One SOAP call. Returns the {Operation}Result element, or throws a refusal
     * with the reason e-Boekhouden gave: a wrong code, an unknown relation, a
     * booking that does not add up.
     */
    private function call(string $operation, array $payload): SimpleXMLElement
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders(['SOAPAction' => '"' . self::NS . '/' . $operation . '"'])
                ->withBody($this->envelope($operation, $payload), 'text/xml; charset=utf-8')
                ->post(config('services.eboekhouden.url'));
        } catch (ConnectionException $e) {
            throw new Refusal('e-Boekhouden is niet bereikbaar: ' . $e->getMessage());
        }

        $xml = @simplexml_load_string($response->body());

        if ($xml === false) {
            throw new Refusal('e-Boekhouden gaf een onleesbaar antwoord (HTTP ' . $response->status() . ').');
        }

        $body = $xml->children(self::ENVELOPE)->Body;

        if (isset($body->children(self::ENVELOPE)->Fault)) {
            throw new Refusal('e-Boekhouden weigerde ' . $operation . ': '
                . trim((string) $body->children(self::ENVELOPE)->Fault->children()->faultstring));
        }

        $result = $body->children(self::NS)->{$operation . 'Response'}->{$operation . 'Result'};

        if ($operation !== 'CloseSession' && filled((string) $result->ErrorMsg->LastErrorCode)) {
            throw new Refusal('e-Boekhouden: ' . trim((string) $result->ErrorMsg->LastErrorDescription)
                . ' (' . trim((string) $result->ErrorMsg->LastErrorCode) . ')');
        }

        return $result;
    }

    /**
     * The elements of a repeated tag as a list. Not with collect() or
     * iterator_to_array(): those key on the tag name, so every relation after
     * the first overwrote the one before it.
     *
     * Nothing when the list is missing: an empty result may leave out the
     * element around it, and then the tag reads as null.
     *
     * @return array<int, SimpleXMLElement>
     */
    private function items(?SimpleXMLElement $elements): array
    {
        $items = [];

        foreach ($elements ?? [] as $element) {
            $items[] = $element;
        }

        return $items;
    }

    private function envelope(string $operation, array $payload): string
    {
        $x = new XMLWriter;
        $x->openMemory();
        $x->startDocument('1.0', 'UTF-8');
        $x->startElementNs('soap', 'Envelope', self::ENVELOPE);
        $x->startElementNs('soap', 'Body', null);
        $x->startElement($operation);
        $x->writeAttribute('xmlns', self::NS);
        $this->write($x, $payload);
        $x->endElement();
        $x->endElement();
        $x->endElement();
        $x->endDocument();

        return $x->outputMemory();
    }

    /** Elements in the order of the array, which is the order of the WSDL. */
    private function write(XMLWriter $x, array $fields): void
    {
        foreach ($fields as $name => $value) {
            if (is_int($name)) {
                $this->write($x, $value);

                continue;
            }

            $x->startElement($name);
            is_array($value) ? $this->write($x, $value) : $x->text((string) $value);
            $x->endElement();
        }
    }
}
