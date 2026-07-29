<?php

namespace App\Http\Controllers;

use App\Domain\Assistant\AssistantLoop;
use App\Domain\Assistant\Contracts\ModelUnavailable;
use App\Domain\Tools\ToolRegistry;
use App\Http\Requests\AssistantAskRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * One question, one answer. No conversation is kept yet: the box is a way to ask
 * something and read the reply, and pretending otherwise would mean storing
 * transcripts nobody has decided the retention rules for.
 */
class AssistantController extends Controller
{
    public function ask(AssistantAskRequest $request, AssistantLoop $loop, ToolRegistry $registry): JsonResponse
    {
        $user = $request->user();
        $tools = [];

        try {
            $answer = $loop->ask(
                user: $user,
                question: $request->validated('question'),
                system: $this->systemPrompt(),
                context: $this->context($user),
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
        ]);
    }

    private function context(User $user): string
    {
        return 'Je praat met ' . $user->name . '. Vandaag is ' . now()->toDateString() . '.';
    }
}
