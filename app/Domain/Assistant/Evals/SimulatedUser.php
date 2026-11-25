<?php

namespace App\Domain\Assistant\Evals;

/**
 * The person at the keyboard during a run. Knows only what the scenario's
 * persona says, and does only what a person in the box can do: click one of the
 * confirmation buttons, pick an option, type an answer, or stop.
 *
 * A move it could not have made in the box — a button that is not there, an
 * option out of range, an empty reply — is handed back as invalid rather than
 * mended, so a run never goes on as if somebody had clicked what they did not.
 */
final class SimulatedUser
{
    private const SYSTEM = <<<'TXT'
Je speelt een medewerker van een installatiebedrijf die de assistent in het systeem Lavoro gebruikt.
Je krijgt wat jij weet en wilt, het gesprek tot nu toe, je eigen laatste bericht, het laatste antwoord van de assistent, en de knoppen die eronder staan.

Regels:
- Je weet alleen wat in "Wat jij weet" staat. Vraagt de assistent iets dat daar niet staat, zeg dan kort dat je het niet weet of kies de redelijkste optie.
- Staat er een bevestigingsknop voor iets dat jij wilt, klik er dan op: precies één knop, op nummer (vanaf 0). Na de klik gaat de assistent vanzelf verder.
- Klopt een bevestigingsknop niet met wat jij wilt, klik dan niet: typ wat er anders moet, of stop.
- Zijn er keuzeknoppen, dan kies je per keuzevraag precies één optie (op nummer, vanaf 0), in volgorde.
- Is een klik niet gelukt, lees dan waarom en beslis opnieuw: een andere knop, een antwoord typen of stoppen.
- Vraagt de assistent iets in tekst, of wacht hij op jou, antwoord dan zoals een drukke planner: kort, in het Nederlands.
- Is alles gedaan wat jij wilt, of vraagt de assistent niets meer, dan stop je.
- Vraag zelf niets nieuws dat niet in "Wat jij weet" staat.

Antwoord met alleen een JSON-object, één van:
{"action": "confirm", "index": 0}               — klikt op bevestigingsknop 0
{"action": "choose", "options": [0]}            — één nummer per keuzevraag, in volgorde
{"action": "reply", "text": "..."}
{"action": "stop", "reason": "..."}
TXT;

    private EvalModel $model;

    public function __construct(private readonly string $persona)
    {
        $this->model = new EvalModel('eval_user');
    }

    public function costMicros(): int
    {
        return $this->model->cost_micros;
    }

    /**
     * @param  array<int, array{said: ?string, answer: string, clicks: array<int, string>}>  $earlier  The turns before this one.
     * @param  string  $said  What the person did to get this answer.
     * @param  array<int, array{tool: string, preview: ?string}>  $buttons  The confirmations still to be clicked.
     * @param  array<int, array{question: string, options: array}>  $choices
     * @param  array<int, string>  $clicked  The clicks on this answer so far, and how they went.
     * @return array{action: string, index?: int, options?: array<int, int>, text?: string, reason?: string}
     */
    public function next(array $earlier, string $said, string $answer, array $buttons, array $choices, array $clicked): array
    {
        $move = $this->model->json(self::SYSTEM, $this->prompt($earlier, $said, $answer, $buttons, $choices, $clicked));

        return self::validated($move, $buttons, $choices);
    }

    private function prompt(array $earlier, string $said, string $answer, array $buttons, array $choices, array $clicked): string
    {
        $lines = ['Wat jij weet en wilt:', $this->persona, '', 'Gesprek tot nu toe:'];

        foreach ($earlier as $turn) {
            if ($turn['said'] !== null) {
                $lines[] = 'Jij: ' . $turn['said'];
            }

            $lines[] = 'Assistent: ' . EvalTranscript::clip($turn['answer'], 1500);

            foreach ($turn['clicks'] as $click) {
                $lines[] = 'Jij klikte op ' . $click;
            }
        }

        $lines[] = '';
        $lines[] = 'Jouw laatste bericht: ' . $said;
        $lines[] = '';
        $lines[] = 'Laatste antwoord van de assistent:';
        $lines[] = $answer;

        foreach ($buttons as $index => $button) {
            $lines[] = '';
            $lines[] = 'Bevestigingsknop ' . $index . ': ' . ($button['preview'] ?? $button['tool']);
        }

        foreach ($choices as $index => $choice) {
            $lines[] = '';
            $lines[] = 'Keuzevraag ' . $index . ': ' . $choice['question'];

            foreach ($choice['options'] as $number => $option) {
                $lines[] = '  ' . $number . '. ' . $option['label'];
            }
        }

        foreach ($clicked as $click) {
            $lines[] = '';
            $lines[] = 'Je klikte al op ' . $click;
        }

        return implode("\n", $lines);
    }

    private static function validated(array $move, array $buttons, array $choices): array
    {
        $action = $move['action'] ?? null;

        if ($action === 'stop') {
            return ['action' => 'stop', 'reason' => (string) ($move['reason'] ?? '')];
        }

        if ($action === 'reply') {
            return filled($move['text'] ?? null)
                ? ['action' => 'reply', 'text' => (string) $move['text']]
                : self::invalid('leeg antwoord', $move);
        }

        if ($action === 'confirm') {
            $index = $move['index'] ?? null;

            return is_int($index) && array_key_exists($index, $buttons)
                ? ['action' => 'confirm', 'index' => $index]
                : self::invalid('bevestigingsknop bestaat niet', $move);
        }

        if ($action === 'choose') {
            $picked = array_values((array) ($move['options'] ?? []));
            $fits = $choices !== [] && count($picked) === count($choices);

            foreach (array_values($choices) as $index => $choice) {
                $fits = $fits && is_int($picked[$index]) && array_key_exists($picked[$index], $choice['options']);
            }

            return $fits ? ['action' => 'choose', 'options' => $picked] : self::invalid('keuze past niet bij de keuzevragen', $move);
        }

        return self::invalid('onbekende actie', $move);
    }

    private static function invalid(string $why, array $move): array
    {
        return ['action' => 'invalid', 'reason' => $why . ': ' . EvalTranscript::text($move)];
    }
}
