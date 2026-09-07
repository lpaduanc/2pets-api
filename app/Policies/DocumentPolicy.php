<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Who may see/download the underlying file: the document's own owner, or an
     * admin/super_admin doing verification (CRMV/RG/diploma review).
     *
     * The legacy `role === 'admin'` fallback mirrors `AdminMiddleware` on purpose:
     * whoever can reach `/admin/documents/pending` must also be able to mint a
     * signed URL for what that endpoint lists.
     */
    public function view(User $user, Document $document): bool
    {
        if ($user->id === $document->user_id) {
            return true;
        }

        return $user->hasAnyRole(['admin', 'super_admin']) || $user->role === 'admin';
    }
}
