<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB
            'document_type' => 'required|string',
        ]);

        $document = app(FileUploadService::class)->upload(
            $request->file('file'),
            $request->user()->id,
            $request->document_type
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

        // Delete file
        Storage::disk('public')->delete($document->file_path);

        // Delete record
        $document->delete();

        return response()->json(['message' => 'Document deleted successfully']);
    }
}
