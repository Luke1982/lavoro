<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Exceptions\SnelStartNotConfigured;
use App\Http\Requests\SnelStartCustomerImportRequest;
use App\Services\SnelStartImport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;

class SnelStartImportController extends Controller
{
    public function importCustomers(SnelStartCustomerImportRequest $request, SnelStartImport $import): RedirectResponse
    {
        $update_existing = $request->boolean('update_existing');

        $counts = $this->fromSnelStart(fn () => $import->customers($update_existing));

        return redirect()->back()->with(
            'success',
            'Uit SnelStart: ' . $counts['created'] . ' nieuwe klanten, ' . $counts['updated'] . ' bijgewerkt.'
        );
    }

    public function importMaterials(SnelStartImport $import): RedirectResponse
    {
        $counts = $this->fromSnelStart(fn () => $import->materials());

        return redirect()->back()->with(
            'success',
            'Uit SnelStart: ' . $counts['created'] . ' nieuwe materialen, ' . $counts['updated'] . ' bijgewerkt.'
        );
    }

    /**
     * What went wrong at SnelStart, said to whoever pressed the button instead
     * of "Er is een serverfout opgetreden". The import runs in one transaction,
     * so after a refusal nothing has changed.
     */
    private function fromSnelStart(callable $work): array
    {
        try {
            return $work();
        } catch (SnelStartNotConfigured $e) {
            throw new Refusal($e->getMessage());
        } catch (ConnectionException $e) {
            report($e);

            throw new Refusal('SnelStart is niet bereikbaar. Er is niets veranderd; probeer het later opnieuw.');
        } catch (RequestException $e) {
            if (in_array($e->response->status(), [401, 403], true)) {
                throw new Refusal('SnelStart weigert de sleutels. Controleer de koppeling bij Technisch beheer.');
            }

            report($e);

            throw new Refusal('SnelStart gaf een fout (' . $e->response->status() . '). '
                . 'Er is niets veranderd; probeer het later opnieuw.');
        }
    }
}
