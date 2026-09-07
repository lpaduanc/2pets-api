<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Admin/owner view of an uploaded document (CRMV, RG, diploma, ...).
 *
 * `document_url` is a short-lived SIGNED route (`Document::signedFileUrl()`), never
 * the disk's raw public path: `FileUploadService::upload()` stores these files on
 * the `public` disk, so an unsigned link would be a permanent, guessable,
 * unauthenticated URL to a sensitive personal document (LGPD art. 5º II). It is
 * only minted when the requester passes `DocumentPolicy::view` and expires after
 * `Document::FILE_URL_TTL_MINUTES`.
 *
 * Expects `user` (and, when the avatar is needed, `user.media`) eager-loaded.
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'original_name' => $this->original_name,
            'file_type' => $this->file_type,
            'file_size' => $this->file_size,
            'verification_status' => $this->verification_status,
            'verification_notes' => $this->verification_notes,
            'verified_at' => $this->verified_at?->toISOString(),
            'document_url' => $this->documentUrl($request),
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * `null` when there is no authenticated viewer or the viewer fails
     * `DocumentPolicy::view` — the frontend already falls back to a placeholder.
     */
    private function documentUrl(Request $request): ?string
    {
        $viewer = $request->user();

        if (! $viewer || Gate::forUser($viewer)->denies('view', $this->resource)) {
            return null;
        }

        return $this->resource->signedFileUrl();
    }
}
