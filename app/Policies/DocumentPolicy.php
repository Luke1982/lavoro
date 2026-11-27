<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\Share;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Determine whether the user can see documents.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('document.see');
    }

    /**
     * This one document: with the right to see documents, on a record this person
     * may read (Document::scopeVisibleTo). Downloading and previewing ask this.
     */
    public function view(User $user, Document $document): bool
    {
        return Document::visibleTo($user)->whereKey($document->id)->exists();
    }

    /**
     * Determine whether the user can upload documents.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('document.upload');
    }

    /** Changing one this person may open (view). */
    public function update(User $user, Document $document): bool
    {
        return $this->updateAny($user) && $this->view($user, $document);
    }

    /**
     * Sending one along from the record it is on, as a document on a quote goes to
     * the customer with it: changing where it goes, like changing it at all.
     */
    public function share(User $user, Document $document): bool
    {
        return $this->update($user, $document) && Share::mayShareFrom($user, $document->attachedRecord());
    }

    /** Removing one this person may open (view). */
    public function delete(User $user, Document $document): bool
    {
        return $this->deleteAny($user) && $this->view($user, $document);
    }

    /**
     * The right to change documents at all, for a bulk action before it knows
     * which; each document in it is then held to update.
     */
    public function updateAny(User $user): bool
    {
        return $user->hasPermission('document.update');
    }

    /**
     * The right to remove documents at all, for a bulk action before it knows
     * which; each document in it is then held to delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('document.delete');
    }
}
