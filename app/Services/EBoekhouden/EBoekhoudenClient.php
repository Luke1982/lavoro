<?php

namespace App\Services\EBoekhouden;

use App\Domain\Integrations\IntegrationUnavailable;
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
 *
 * Two kinds of user. The panel books MajorLabel's own invoices with the
 * account in .env, and that is what the container hands out. A customer that
 * links its own administration gets a client made with forCustomer(), with the
 * codes of its connection; the .env account is never reached from there.
 */
class EBoekhoudenClient
{
    private const NS = 'http://www.e-boekhouden.nl/soap';

    private const ENVELOPE = 'http://schemas.xmlsoap.org/soap/envelope/';

    /** The fields of a relation, in the order of the WSDL, which is the order they must be sent in. */
    public const RELATION_FIELDS = [
        'ID', 'AddDatum', 'Code', 'Bedrijf', 'Contactpersoon', 'Geslacht', 'Adres', 'Postcode', 'Plaats', 'Land',
        'Adres2', 'Postcode2', 'Plaats2', 'Land2', 'Telefoon', 'GSM', 'FAX', 'Email', 'Site', 'Notitie',
        'Bankrekening', 'Girorekening', 'BTWNummer', 'KvkNummer', 'Aanhef', 'IBAN', 'BIC', 'BP',
        'Def1', 'Def2', 'Def3', 'Def4', 'Def5', 'Def6', 'Def7', 'Def8', 'Def9', 'Def10',
        'LA', 'Gb_ID', 'GeenEmail', 'NieuwsbriefgroepenCount',
    ];

    /** @var array{url: string, username: ?string, security_code_1: ?string, security_code_2: ?string} */
    private array $credentials;

    private string $not_configured;

    public function __construct()
    {
        $this->credentials = [
            'url' => (string) config('services.eboekhouden.url'),
            'username' => config('services.eboekhouden.username'),
            'security_code_1' => config('services.eboekhouden.security_code_1'),
            'security_code_2' => config('services.eboekhouden.security_code_2'),
        ];
        $this->not_configured = 'e-Boekhouden is niet ingesteld: EBOEKHOUDEN_USERNAME en de twee beveiligingscodes ontbreken in .env.';
    }

    public static function forCustomer(?string $username, ?string $security_code_1, ?string $security_code_2): self
    {
        $client = new self;
        $client->credentials = [
            'url' => (string) config('services.eboekhouden.url'),
            'username' => $username,
            'security_code_1' => $security_code_1,
            'security_code_2' => $security_code_2,
        ];
        $client->not_configured = 'e-Boekhouden is niet ingesteld: de gebruikersnaam of een beveiligingscode ontbreekt. '
            . 'Vul ze in onder Instellingen → Koppelingen.';

        return $client;
    }

    public function configured(): bool
    {
        return filled($this->credentials['username'])
            && filled($this->credentials['security_code_1'])
            && filled($this->credentials['security_code_2']);
    }

    /** Opens and closes a session: the codes work. */
    public function verify(): void
    {
        $this->session(fn () => null);
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
            'SecurityCode2' => $this->credentials['security_code_2'],
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
                'SecurityCode2' => $this->credentials['security_code_2'],
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
            'SecurityCode2' => $this->credentials['security_code_2'],
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

    /**
     * Relations with every field e-Boekhouden keeps, by the names of the WSDL.
     * Without an id: all of them.
     *
     * @return array<int, array<string, string>>
     */
    public function fullRelations(int $id = 0): array
    {
        return $this->session(function (string $session) use ($id) {
            $result = $this->call('GetRelaties', [
                'SessionID' => $session,
                'SecurityCode2' => $this->credentials['security_code_2'],
                'cFilter' => ['Trefwoord' => '', 'Code' => '', 'ID' => $id],
            ]);

            return array_map(function (SimpleXMLElement $relation) {
                $fields = [];

                foreach (self::RELATION_FIELDS as $field) {
                    $fields[$field] = trim((string) $relation->{$field});
                }

                return $fields;
            }, $this->items($result->Relaties->cRelatie));
        });
    }

    /**
     * Writes a relation whole: AddRelatie without an id, UpdateRelatie with
     * one. Returns its id. Pass what fullRelations() gave with the changes laid
     * over it, so fields Lavoro does not know are written back as they were.
     *
     * @param  array<string, mixed>  $fields
     */
    public function saveRelation(array $fields): int
    {
        $defaults = [
            'ID' => 0, 'AddDatum' => now()->format('Y-m-d'), 'Land' => 'NL', 'Notitie' => 'Lavoro', 'BP' => 'B',
            'Gb_ID' => 0, 'GeenEmail' => 1, 'NieuwsbriefgroepenCount' => 0,
        ];

        $relation = [];

        foreach (self::RELATION_FIELDS as $field) {
            $value = $fields[$field] ?? null;
            $relation[$field] = filled($value) ? $value : ($defaults[$field] ?? '');
        }

        $id = (int) $relation['ID'];

        $result = $this->session(fn (string $session) => $this->call($id === 0 ? 'AddRelatie' : 'UpdateRelatie', [
            'SessionID' => $session,
            'SecurityCode2' => $this->credentials['security_code_2'],
            'oRel' => $relation,
        ]));

        return $id === 0 ? (int) $result->Rel_ID : $id;
    }

    /**
     * Articles, all of them or the one with this id.
     *
     * @return array<int, array{id: string, code: string, name: string, unit: string, cost_price: string, price: string, active: bool}>
     */
    public function articles(int $id = 0): array
    {
        return $this->session(function (string $session) use ($id) {
            $result = $this->call('GetArtikelen', [
                'SessionID' => $session,
                'SecurityCode2' => $this->credentials['security_code_2'],
                'cFilter' => [
                    'ArtikelID' => $id,
                    'ArtikelOmschrijving' => '',
                    'ArtikelCode' => '',
                    'GroepOmschrijving' => '',
                    'GroepCode' => '',
                ],
            ]);

            return array_map(fn (SimpleXMLElement $article) => [
                'id' => (string) $article->ArtikelID,
                'code' => trim((string) $article->ArtikelCode),
                'name' => trim((string) $article->ArtikelOmschrijving),
                'unit' => trim((string) $article->Eenheid),
                'cost_price' => (string) $article->InkoopprijsExclBTW,
                'price' => (string) $article->VerkoopprijsExclBTW,
                'active' => strtolower((string) $article->Actief) !== 'false',
            ], $this->items($result->Artikelen->cArtikel));
        });
    }

    /**
     * An invoice in e-Boekhouden's own invoicing, made from an invoice
     * template there. With $book it is also booked in the ledger; without, it
     * waits there to be checked and booked by hand. Returns the number
     * e-Boekhouden gave it.
     *
     * @param  array{relation_code: string, date: string, payment_days: int, template: string, description: string, book: bool, lines: array<int, array{quantity: float, unit: string, code: string, description: string, price: float, vat_code: string, revenue_account: string}>}  $invoice
     */
    public function addSalesInvoice(array $invoice): string
    {
        $flag = fn (bool $value) => $value ? 'true' : 'false';

        $result = $this->session(fn (string $session) => $this->call('AddFactuur', [
            'SessionID' => $session,
            'SecurityCode2' => $this->credentials['security_code_2'],
            'oFact' => [
                'Factuurnummer' => '',
                'Relatiecode' => $invoice['relation_code'],
                'Datum' => $invoice['date'],
                'Betalingstermijn' => (string) $invoice['payment_days'],
                'Factuursjabloon' => $invoice['template'],
                'PerEmailVerzenden' => $flag(false),
                'EmailOnderwerp' => '',
                'EmailBericht' => '',
                'EmailVanAdres' => '',
                'EmailVanNaam' => '',
                'AutomatischeIncasso' => $flag(false),
                'IncassoIBAN' => '',
                'IncassoMachtigingSoort' => '',
                'IncassoMachtigingID' => '',
                'IncassoMachtigingDatumOndertekening' => $invoice['date'],
                'IncassoMachtigingFirst' => $flag(false),
                'IncassoRekeningNummer' => '',
                'IncassoTnv' => '',
                'IncassoPlaats' => '',
                'IncassoOmschrijvingRegel1' => '',
                'IncassoOmschrijvingRegel2' => '',
                'IncassoOmschrijvingRegel3' => '',
                'InBoekhoudingPlaatsen' => $flag($invoice['book']),
                'BoekhoudmutatieOmschrijving' => $invoice['description'],
                'Regels' => array_map(fn (array $line) => ['cFactuurRegel' => [
                    'Aantal' => (string) $line['quantity'],
                    'Eenheid' => $line['unit'],
                    'Code' => $line['code'],
                    'Omschrijving' => $line['description'],
                    'PrijsPerEenheid' => (string) $line['price'],
                    'BTWCode' => $line['vat_code'],
                    'TegenrekeningCode' => $line['revenue_account'],
                    'KostenplaatsID' => 0,
                ]], $invoice['lines']),
            ],
        ]));

        return trim((string) $result->Factuurnummer);
    }

    /** @return array<int, array{id: int, code: string, name: string, city: string, email: string, vat_number: string, coc_number: string}> */
    private function relations(string $session, array $filter): array
    {
        $result = $this->call('GetRelaties', [
            'SessionID' => $session,
            'SecurityCode2' => $this->credentials['security_code_2'],
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
            throw new Refusal($this->not_configured);
        }

        $session = (string) $this->call('OpenSession', [
            'Username' => $this->credentials['username'],
            'SecurityCode1' => $this->credentials['security_code_1'],
            'SecurityCode2' => $this->credentials['security_code_2'],
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
     * booking that does not add up. A service that is down or busy is
     * IntegrationUnavailable, which a queued job tries again later.
     */
    private function call(string $operation, array $payload): SimpleXMLElement
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders(['SOAPAction' => '"' . self::NS . '/' . $operation . '"'])
                ->withBody($this->envelope($operation, $payload), 'text/xml; charset=utf-8')
                ->post($this->credentials['url']);
        } catch (ConnectionException $e) {
            throw new IntegrationUnavailable('e-Boekhouden is niet bereikbaar: ' . $e->getMessage());
        }

        $xml = @simplexml_load_string($response->body());

        /**
         * Not a SOAP answer at all: an error page from something in front of
         * the service. A fault does come back as a 500, but inside an envelope,
         * so the status alone cannot tell "busy" from "refused".
         */
        if ($xml === false || !isset($xml->children(self::ENVELOPE)->Body)) {
            throw $response->status() === 429 || $response->serverError()
                ? new IntegrationUnavailable('e-Boekhouden is even niet beschikbaar (HTTP ' . $response->status() . ').')
                : new Refusal('e-Boekhouden gaf een onleesbaar antwoord (HTTP ' . $response->status() . ').');
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
