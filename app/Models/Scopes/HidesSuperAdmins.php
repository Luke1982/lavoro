<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Verbergt de accounts van MajorLabel voor de klant.
 *
 * Als globale scope en niet per lijst: gebruikers worden op tientallen plekken
 * opgehaald -- het gebruikersscherm, rollen, meldingen, de zoekbalk, de
 * planner -- en één vergeten plek is genoeg om ons account alsnog te tonen,
 * met een prullenbak ernaast.
 *
 * Twee uitzonderingen, allebei nodig:
 *
 * - Is er nog niemand ingelogd, dan doet de scope niets. Anders vindt het
 *   inloggen zijn eigen gebruiker niet meer en komt een superbeheerder er
 *   nooit meer in. Commando's en workers vallen hier ook onder.
 * - Een superbeheerder ziet ze wel, anders ziet hij zichzelf niet.
 *
 * Auth::hasUser() en niet Auth::user(): dat laatste zou de gebruiker willen
 * ophalen, wat deze scope opnieuw aanroept.
 */
class HidesSuperAdmins implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (!Auth::hasUser()) {
            return;
        }

        $user = Auth::user();

        if ($user instanceof $model && $user->isSuperAdmin()) {
            return;
        }

        $builder->withoutSuperAdmins();
    }
}
