<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentFileController extends Controller
{
    /**
     * Stream a document file (CRMV/RG/diploma) through a short-lived signed URL.
     *
     * Authorization happens once, at mint time — `DocumentResource::documentUrl()`
     * only calls `Document::signedFileUrl()` after `DocumentPolicy::view` passes for
     * the requester. From then on the `signed` route middleware (registered in
     * routes/api.php) is what protects this endpoint: the signature can't be forged
     * or reused past `Document::FILE_URL_TTL_MINUTES`. There is deliberately no
     * `auth:sanctum` here — an `<img src>`/direct link never sends the bearer token.
     */
    public function show(Request $request, Document $document): StreamedResponse
    {
        $disk = Storage::disk('public');

        abort_unless($disk->exists($document->file_path), 404, 'Arquivo não encontrado.');

        Log::info('Document file streamed via signed URL', [
            'document_id' => $document->id,
            'document_owner_id' => $document->user_id,
            'ip' => $request->ip(),
        ]);

        return $disk->response($document->file_path, $document->original_name);
    }
}
