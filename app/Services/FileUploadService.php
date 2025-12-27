<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FileUploadService
{
    protected $allowedTypes = ['pdf', 'jpg', 'jpeg', 'png', 'heic', 'heif'];
    protected $maxSize = 10485760; // 10MB in bytes

    /**
     * Upload a file and create a document record
     */
    public function upload(UploadedFile $file, int $userId, string $documentType): Document
    {
        // Validate
        $this->validate($file);

        // Handle HEIC conversion if needed (placeholder for now)
        if (in_array(strtolower($file->getClientOriginalExtension()), ['heic', 'heif'])) {
            $file = $this->convertHeicToJpeg($file);
        }

        // Generate unique filename
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $extension;

        // Store in user-specific directory
        // storage/app/public/documents/{userId}/{filename}
        $path = $file->storeAs(
            "documents/{$userId}",
            $filename,
            'public'
        );

        // Create database record
        return Document::create([
            'user_id' => $userId,
            'document_type' => $documentType,
            'file_name' => $filename,
            'file_path' => $path,
            'file_type' => strtolower($extension),
            'file_size' => $file->getSize(),
            'original_name' => $file->getClientOriginalName(),
        ]);
    }

    /**
     * Validate file size and type
     */
    private function validate(UploadedFile $file): void
    {
        if ($file->getSize() > $this->maxSize) {
            throw ValidationException::withMessages([
                'file' => 'File size exceeds 10MB limit'
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $this->allowedTypes)) {
            throw ValidationException::withMessages([
                'file' => 'Invalid file type. Allowed: PDF, JPG, PNG, HEIC'
            ]);
        }
    }

    /**
     * Convert HEIC to JPEG
     * Note: This requires ImageMagick or similar on the server.
     * For now, we'll return the file as is, assuming frontend or another process handles it,
     * or we store it as HEIC.
     */
    private function convertHeicToJpeg(UploadedFile $file): UploadedFile
    {
        // TODO: Implement actual conversion if server supports it.
        // For now, we accept HEIC storage.
        return $file;
    }
}
