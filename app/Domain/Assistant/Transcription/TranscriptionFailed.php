<?php

namespace App\Domain\Assistant\Transcription;

use App\Exceptions\Refusal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Said to the person as it is: the recording could not be turned into text, and
 * why. When the cause may pass, as with a supplier that did not answer, the
 * screen keeps the recording so it can be sent again.
 */
class TranscriptionFailed extends Refusal
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }

    public function render(Request $request): ?JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $this->getMessage(), 'retry' => $this->retryable], 422)
            : null;
    }

    /** A setting to fix, not something the person did, so it goes to the log as well. */
    public static function missingKey(string $supplier): self
    {
        Log::warning('Uitschrijven bij ' . $supplier . ' kan niet: er is geen sleutel ingesteld');

        return new self('Inspreken staat nog niet aan: de koppeling die spraak omzet in tekst is niet ingesteld. '
            . 'Laat het de beheerder weten.');
    }

    /** The supplier did not answer in time; worth trying again in a moment. */
    public static function unreachable(string $supplier, ConnectionException $exception): self
    {
        Log::warning('Uitschrijven bij ' . $supplier . ' niet bereikbaar', ['error' => $exception->getMessage()]);

        return new self('De dienst die spraak omzet in tekst reageert nu niet. Probeer het zo nog eens.', retryable: true);
    }

    /**
     * A key the supplier turns away is a setting to fix, not something to try
     * again; the details go to the log for whoever fixes it.
     */
    public static function from(Response $response, string $supplier): self
    {
        Log::warning('Uitschrijven bij ' . $supplier . ' mislukt', [
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 500),
        ]);

        $refused = in_array($response->status(), [401, 403], true);

        return new self($refused
            ? 'Inspreken werkt nu niet: de koppeling die spraak omzet in tekst heeft daarvoor geen toestemming. '
                . 'Laat het de beheerder weten.'
            : 'De opname kon niet worden omgezet in tekst. Probeer het nog eens.', retryable: !$refused);
    }
}
