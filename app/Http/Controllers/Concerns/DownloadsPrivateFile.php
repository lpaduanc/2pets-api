<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Download de arquivo em disco privado — mesmo padrão já usado por
 * `ExamController::downloadImage` (URL assinada de curta duração quando o disco suporta,
 * stream como fallback para disco `local`). Extraído para trait porque
 * `ConsultationController::downloadAttachment` precisava do mesmo comportamento sem herdar a
 * lógica de exame.
 */
trait DownloadsPrivateFile
{
    protected function assertFileExistsOnDisk(Filesystem $disk, string $path, string $logContext): void
    {
        if ($disk->exists($path)) {
            return;
        }

        Log::warning("{$logContext}: file missing on disk", ['path' => $path]);
        abort(404, 'Arquivo não encontrado no armazenamento.');
    }

    protected function downloadResponse(
        Filesystem $disk,
        string $path,
        string $downloadName,
        string $mimeType
    ): JsonResponse|StreamedResponse {
        return $this->signedUrlResponse($disk, $path, $downloadName, $mimeType)
            ?? $disk->download($path, $downloadName, [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }

    private function signedUrlResponse(Filesystem $disk, string $path, string $downloadName, string $mimeType): ?JsonResponse
    {
        try {
            $url = $disk->temporaryUrl($path, now()->addMinutes(10));
        } catch (Throwable) {
            return null;
        }

        return response()->json([
            'url' => $url,
            'expires_in' => 600,
            'mime_type' => $mimeType,
            'file_name' => $downloadName,
        ]);
    }
}
