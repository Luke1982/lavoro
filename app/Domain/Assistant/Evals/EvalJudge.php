<?php

namespace App\Domain\Assistant\Evals;

use App\Domain\Assistant\SystemPrompt;

/**
 * Reads a finished conversation and grades what only a reader can: whether it
 * was Dutch, short, honest, and asked one thing at a time. Everything that can
 * be counted is a Check in the scenario instead; the judge is for the rest.
 */
final class EvalJudge
{
    private const GENERAL = [
        'Schrijft Nederlands, ook in korte tussenzinnen.',
        'Kort en zakelijk: geen aankondigingen van wat hij gaat doen, geen samenvatting van eigen stappen, geen herhaling van wat al in een knop of keuze staat.',
        'Verzint niets: elk nummer, elke naam, datum, prijs en technische eigenschap komt uit een toolresultaat, een bijlage of de gebruiker.',
        'Stelt hooguit één vraag tegelijk, en legt een keuze tussen concrete mogelijkheden voor met knoppen (ask_which_one) in plaats van in tekst.',
        'Doet niets dubbel: biedt een al uitgevoerde actie niet opnieuw aan en maakt niets twee keer aan. Iets opnieuw opzoeken in een latere beurt telt hier niet: eerdere toolresultaten gaan niet mee naar de volgende beurt.',
        'Vraagt naar wat ontbreekt (duur, datum, product, monteur) in plaats van het aan te nemen.',
    ];

    private const SYSTEM = <<<'TXT'
Je beoordeelt een gesprek tussen een gebruiker en de assistent van Lavoro, een systeem voor installatie- en servicebedrijven.
Je ziet per beurt wat de gebruiker zei, welke tools de assistent aanriep met welke argumenten en wat die teruggaven, wat de assistent schreef, en daarna wat de gebruiker deed: op welke knop hij klikte en hoe dat afliep, wat hij koos of typte.
Een heel lang toolresultaat is ingekort; waar dat zo is, staat "[ingekort: ...]". Wat de assistent noemt en in het weggelaten deel kan hebben gestaan, reken je niet als verzonnen.
Beoordeel elk criterium afzonderlijk en streng: twijfel is geen voldoende. Geef bij een onvoldoende het concrete voorbeeld (beurtnummer en citaat).

Antwoord met alleen een JSON-object:
{"criteria": [{"criterion": "...", "pass": true, "note": "..."}], "worst_problem": "één zin, of leeg"}
TXT;

    private EvalModel $model;

    public function __construct()
    {
        $this->model = new EvalModel('eval_judge');
    }

    public function costMicros(): int
    {
        return $this->model->cost_micros;
    }

    /** @return array{criteria: array<int, array{criterion: string, pass: bool, note: string}>, worst_problem: string} */
    public function grade(EvalScenario $scenario, EvalTranscript $transcript): array
    {
        $criteria = array_merge(self::GENERAL, $scenario->rubric());

        $verdict = $this->model->json(self::SYSTEM, implode("\n", [
            'Wat de gebruiker wilde: ' . $scenario->persona(),
            'Wat de assistent bij elke vraag over de gebruiker meekreeg (geldt dus als bron): '
                . ($scenario->user() ? SystemPrompt::context($scenario->user()) : ''),
            '',
            'Criteria:',
            ...array_map(fn ($criterion, $index) => ($index + 1) . '. ' . $criterion, $criteria, array_keys($criteria)),
            '',
            'Gesprek:',
            $transcript->markdown(),
        ]));

        return [
            'criteria' => array_values(array_map(fn ($row) => [
                'criterion' => (string) ($row['criterion'] ?? ''),
                'pass' => (bool) ($row['pass'] ?? false),
                'note' => (string) ($row['note'] ?? ''),
            ], (array) ($verdict['criteria'] ?? []))),
            'worst_problem' => (string) ($verdict['worst_problem'] ?? ''),
        ];
    }
}
