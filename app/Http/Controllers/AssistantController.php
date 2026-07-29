<?php

namespace App\Http\Controllers;

use App\Domain\Assistant\AssistantLoop;
use App\Domain\Assistant\Contracts\ModelUnavailable;
use App\Domain\Assistant\PageContext;
use App\Domain\Tools\ToolRegistry;
use App\Http\Requests\AssistantAskRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A conversation that lives only as long as the box is open.
 *
 * The thread on screen is the thread that gets sent, so nothing is stored here
 * and there is no transcript to decide retention rules for. It is capped at a
 * handful of exchanges: a follow-up needs what was just said, not everything.
 *
 * That the history arrives from the browser costs nothing in safety. Every tool
 * authorises against the person asking on each call, so a doctored history can
 * mislead the assistant about what it once said but cannot reach a single record
 * its user could not already open.
 */
class AssistantController extends Controller
{
    public function ask(
        AssistantAskRequest $request,
        AssistantLoop $loop,
        ToolRegistry $registry,
        PageContext $pages,
    ): JsonResponse {
        $user = $request->user();
        $tools = [];

        try {
            $answer = $loop->ask(
                user: $user,
                question: $request->validated('question'),
                system: $this->systemPrompt(),
                context: $this->context($user, $pages->describe($request->validated('page'))),
                history: $request->validated('history') ?? [],
                onTool: function (string $name, array $arguments, bool $failed) use (&$tools) {
                    $tools[] = ['name' => $name, 'arguments' => $arguments, 'failed' => $failed];
                },
            );
        } catch (ModelUnavailable $e) {
            return response()->json(['message' => $this->explain($e)], 503);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'answer' => $answer->text,
            'tools' => $tools,
            'difficulty' => $registry->requiredDifficultyFor($user),
        ]);
    }

    private function explain(ModelUnavailable $e): string
    {
        return match ($e->reason->value) {
            'no_credit' => 'Het AI-tegoed is op. Neem contact op met de beheerder.',
            'bad_credentials' => 'De AI-dienst weigert de sleutel. Neem contact op met de beheerder.',
            'unreachable' => 'De AI-dienst is niet bereikbaar. Probeer het zo nog eens.',
            default => 'De AI-dienst gaf een fout terug.',
        };
    }

    /**
     * Frozen for everybody, every day: this sits behind the cache marker with the
     * tool definitions, and a cached prefix only earns its keep while it stays
     * byte-for-byte identical.
     */
    private function systemPrompt(): string
    {
        return implode("\n", [
            'Je bent de assistent van Lavoro, een systeem voor installatie- en servicebedrijven.',
            'Je schrijft altijd Nederlands, ook de zinnetjes tussendoor waarin je zegt wat je gaat opzoeken.',
            'Je antwoordt kort en concreet. Gebruik korte alinea\'s of een opsomming, geen tabellen.',
            '',
            'Gebruik de tools om echte gegevens op te halen. Verzin nooit een werkbonnummer,',
            'klantnaam of datum: als je het niet uit een tool hebt, zeg je dat je het niet weet.',
            'Een tool geeft alleen terug wat deze gebruiker mag zien, dus een leeg resultaat',
            'betekent "niets gevonden of niets zichtbaar", niet "het bestaat niet".',
            '',
            'Je kunt alleen bij wat je tools je geven, en je kunt niets wijzigen: je leest mee,',
            'je voert niets uit. Word je iets gevraagd waar geen tool voor is, zeg dan in één zin',
            'wat je wél kunt op dat vlak en wat niet, in plaats van te gokken of om meer uitleg',
            'te vragen. Bijvoorbeeld: "Ik kan de tijdlijn van een werkbon doorzoeken, maar ik kan',
            'geen offertes opstellen." Noem daarbij geen toolnamen; beschrijf het in gewone woorden.',
            'Vraag alleen om verduidelijking als de vraag zelf onduidelijk is, niet als hij',
            'duidelijk maar onmogelijk is.',
        ]);
    }

    /**
     * Everything that changes per person, per day and per page, kept out of the
     * system prompt so the cached prefix stays byte-for-byte identical.
     */
    private function context(User $user, string $page): string
    {
        $lines = ['Je praat met ' . $user->name . '. Vandaag is ' . now()->toDateString() . '.'];

        if ($page !== '') {
            $lines[] = $page;
        }

        return implode(' ', $lines);
    }
}
