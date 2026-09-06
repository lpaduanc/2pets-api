<?php

namespace App\Http\Controllers;

use App\Http\Requests\Document\UploadDocumentRequest;
use App\Models\Document;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function upload(UploadDocumentRequest $request)
    {
        $document = app(FileUploadService::class)->upload(
            $request->file('file'),
            $request->user()->id,
            $request->validated()['document_type']
        );

        return response()->json([
            'message' => 'Document uploaded successfully',
            'document' => $document,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $document = Document::where('user_id', $request->user()->id)
            ->findOrFail($id);

        Storage::disk('public')->delete($document->file_path);
        $document->delete();

        return response()->json(['message' => 'Document deleted successfully']);
    }
}
