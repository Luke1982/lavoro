<?php

namespace App\Domain\Tools\Read;

use App\Domain\Assistant\Reading\Conditions;
use App\Domain\Assistant\Reading\RecordKinds;
use App\Domain\Assistant\Reading\RecordReader;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Exceptions\Refusal;
use App\Models\AssistantFilter;
use App\Models\User;

/**
 * The assistant's general read: one record by id, or a count, total or grouping
 * over every visible record that meets some conditions, or a link to the
 * overview page showing exactly those records.
 *
 * Nothing about access is decided here. The kinds come from RecordKinds (the
 * policies), the rows from each model's own visibility scope, the fields from
 * the maps the timeline uses. See docs/development/assistant-read-layer.md.
 *
 * It never returns a list of rows: a question about forty werkbonnen is
 * answered with the number and a link, so the list is read where it can be
 * sorted, opened and acted on, and not retyped by a model that might drop one.
 */
class ReadRecordsTool implements Tool
{
    public static function name(): string
    {
        return 'read_records';
    }

    public function description(): string
    {
        return 'Eén record op id, of het aantal (sum: totaal, group: per waarde) van alle records die aan where '
            . 'voldoen, met link: true ook een link naar het overzicht met precies die records. Voor hoeveel, '
            . 'welk totaal en welke; noem nooit veel records in de chat.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => [
                    'type' => 'string',
                    'description' => 'werkbon, storing, machine, klant, afspraak, onderhoudscontract, offerte, '
                        . 'product, materiaal, project, medewerker, ...; een onbekende geeft de lijst.',
                ],
                'id' => [
                    'type' => 'integer',
                    'description' => 'Eén record met alle velden.',
                ],
                'where' => [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                    'description' => 'En: {"field":"order_date","op":"<","value":"2026-10-25"}, {"has":"events",'
                        . '"not":true}, {"field":"customer.city","op":"=","value":"Tiel"}. op: = != < > <= >= in like '
                        . 'empty filled. Datum JJJJ-MM-DD.',
                ],
                'sum' => [
                    'type' => 'string',
                    'description' => 'Getalveld om op te tellen.',
                ],
                'group' => [
                    'type' => 'string',
                    'description' => 'Veld om per waarde te tellen.',
                ],
                'link' => [
                    'type' => 'boolean',
                    'description' => 'Ook een link naar het overzicht.',
                ],
            ],
            'required' => ['kind'],
            'additionalProperties' => false,
        ];
    }

    /**
     * May this person read this kind at all; which records is the scope's
     * business. An unknown kind passes, and so does a kind the assistant never
     * reads (a koppeling, an instelling), so execute can say which ones exist and
     * that this one is not among them, rather than "no rights".
     */
    public function authorize(User $user, array $arguments): bool
    {
        $class = RecordKinds::classFor(is_string($arguments['kind'] ?? null) ? $arguments['kind'] : null);

        return $class === null || !RecordKinds::isReadable($class) || RecordKinds::mayRead($class, $user);
    }

    /** Velden en voorwaarden moeten uit de vraag naar kolommen vertaald worden, maar de foutmeldingen zeggen welke er zijn. */
    public static function difficulty(): int
    {
        return 5;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public static function availableTo(): array
    {
        return ToolProfile::all();
    }

    public function execute(ToolCall $call): ToolResult
    {
        $class = RecordKinds::classFor($call->stringArgument('kind'));

        if ($class === null) {
            return ToolResult::failed('Onbekend soort record. Kies uit: ' . implode(', ', RecordKinds::readableBy($call->user)) . '.');
        }

        $noun = RecordKinds::noun($class);

        if (!RecordKinds::isReadable($class)) {
            return ToolResult::failed('De assistent leest geen records van het soort "' . $noun . '". Kies uit: '
                . implode(', ', RecordKinds::readableBy($call->user)) . '.');
        }

        if ($call->wasGiven('id')) {
            $also = array_values(array_filter(['where', 'sum', 'group', 'link'], fn (string $key) => $call->wasGiven($key)));

            if ($also !== []) {
                return ToolResult::failed('Geef óf id (één record), óf where met sum, group of link (een telling), niet allebei: '
                    . 'id gaat niet samen met ' . implode(', ', $also) . '.');
            }

            $record = app(RecordReader::class)->read($class, (int) $call->integerArgument('id'), $call->user);

            return $record === null
                ? ToolResult::notFound(ucfirst($noun) . ' #' . $call->integerArgument('id'))
                : ToolResult::ok($record, ucfirst($noun) . ' #' . $call->integerArgument('id') . ' gelezen');
        }

        $where = (array) ($call->argument('where') ?? []);
        $conditions = new Conditions($class, $call->user);

        try {
            $query = $conditions->query($where);
            $content = ['soort' => $noun, 'aantal' => $conditions->count($query)];

            if ($sum = $call->stringArgument('sum')) {
                $content['totaal_' . $sum] = $conditions->sum($query, $sum);
            }

            if ($group = $call->stringArgument('group')) {
                $grouped = $conditions->group($query, $group, $sum);
                $content['groepen'] = $grouped['groups'];

                if ($grouped['more']) {
                    $content['opmerking'] = 'Alleen de ' . count($grouped['groups']) . ' grootste groepen staan hier.';
                }
            }
        } catch (Refusal $refusal) {
            return ToolResult::failed($refusal->getMessage());
        }

        if (!$call->booleanArgument('link')) {
            return ToolResult::ok($content, $content['aantal'] . ' ' . RecordKinds::plural($class) . ' geteld');
        }

        if (RecordKinds::overview($class) === null) {
            $content['opmerking'] = 'Voor ' . RecordKinds::plural($class) . ' is er geen overzicht om naar te linken; noem het aantal.';

            return ToolResult::ok($content, $content['aantal'] . ' ' . RecordKinds::plural($class) . ' geteld');
        }

        if (!RecordKinds::mayList($class, $call->user)) {
            $content['opmerking'] = 'Deze gebruiker mag het overzicht van ' . RecordKinds::plural($class)
                . ' niet openen, dus geen link; noem het aantal.';

            return ToolResult::ok($content, $content['aantal'] . ' ' . RecordKinds::plural($class) . ' geteld');
        }

        $summary = $conditions->describe($where);
        $filter = AssistantFilter::record($call->user, $class, $where, $summary);

        $content['link'] = $filter->url();
        $content['samenvatting'] = $summary;

        return ToolResult::ok($content, 'Link naar ' . $content['aantal'] . ' ' . RecordKinds::plural($class));
    }
}
