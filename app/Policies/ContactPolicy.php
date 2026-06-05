<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('contact.read');
    }

    public function view(User $user, Contact $contact): bool
    {
        return $user->hasPermission('contact.read');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('contact.create');
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->hasPermission('contact.update');
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $user->hasPermission('contact.delete');
    }
}
