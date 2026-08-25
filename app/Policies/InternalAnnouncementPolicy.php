<?php

namespace App\Policies;

use App\Models\InternalAnnouncement;
use App\Models\User;

class InternalAnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('internalannouncement.read');
    }

    public function view(User $user, InternalAnnouncement $internal_announcement): bool
    {
        return $user->hasPermission('internalannouncement.read');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('internalannouncement.create');
    }

    public function update(User $user, InternalAnnouncement $internal_announcement): bool
    {
        return $user->hasPermission('internalannouncement.update');
    }

    public function delete(User $user, InternalAnnouncement $internal_announcement): bool
    {
        return $user->hasPermission('internalannouncement.delete');
    }

    /**
     * Geen recht maar een rol in dit ene bericht: alleen wie het gekregen heeft
     * kan bevestigen dat hij het gelezen heeft, en alleen zolang het nog staat.
     * Een beheerder die niet in de doelgroep zit heeft niets te bevestigen.
     *
     * Of hij al bevestigd heeft staat er bewust niet bij. Nog eens drukken —
     * twee tabbladen open, een trage verbinding — is niets doen, en niets doen
     * hoort geen foutmelding op te leveren. Wie het niet mag krijgt er wel een.
     */
    public function acknowledge(User $user, InternalAnnouncement $internal_announcement): bool
    {
        if ($internal_announcement->expires_on?->isBefore(today())) {
            return false;
        }

        return $internal_announcement->recipients()->whereKey($user->getKey())->exists();
    }
}
