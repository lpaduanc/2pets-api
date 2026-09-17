<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FileUploadService
{
    protected $allowedTypes = ['pdf', 'jpg', 'jpeg', 'png', 'heic', 'heif'];

    protected $maxSize = 10485760; // 10MB in bytes

    /**
     * Real MIME types (as reported by finfo) we accept. Extension alone cannot be
     * trusted — an attacker can rename `payload.php` to `payload.jpg`.
     */
    protected array $allowedMimeTypes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/heic',
        'image/heif',
    ];

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
        $filename = Str::uuid().'.'.$extension;

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
     * Upload an exam attachment (image or PDF) to a PRIVATE disk.
     *
     * Prontuário veterinário / exames = dado sensível (LGPD art. 5º, II). Nunca
     * armazenar em disco público. Sempre servir via download autorizado (controller)
     * ou URL assinada de curta duração.
     *
     * Returns the relative storage path (to be persisted on `exam_images.file_path`).
     */
    public function uploadForExam(UploadedFile $file, int $examId, int $uploaderId): string
    {
        $this->validate($file);

        // Deep MIME inspection: never trust only the extension / browser-reported type.
        $this->assertRealMimeTypeAllowed($file);

        // Best-effort malware scan. No-op unless CLAMAV_ENABLED=true.
        $this->scanForMalware($file);

        $extension = strtolower($file->getClientOriginalExtension());
        $filename = Str::uuid().'.'.$extension;

        // exams/{examId}/{uploaderId}/{uuid}.ext — uploaderId ajuda auditoria
        // mesmo que uploader e tutor sejam pessoas diferentes (vet faz upload).
        $path = $file->storeAs(
            "exams/{$examId}/{$uploaderId}",
            $filename,
            $this->privateDisk()
        );

        if ($path === false || $path === null) {
            throw ValidationException::withMessages([
                'file' => 'Falha ao salvar o arquivo. Tente novamente.',
            ]);
        }

        return $path;
    }

    /**
     * Upload de um anexo de prontuário (imagem ou PDF) para o disco PRIVADO.
     *
     * Mesmas regras de `uploadForExam`: MIME real inspecionado, scan de malware (no-op
     * fora de CLAMAV_ENABLED), nunca disco público. Prontuário é dado sensível (LGPD art.
     * 5º, II) — download só por rota autorizada (ver `ConsultationController::downloadAttachment`).
     *
     * Returns the relative storage path (persisted em `medical_record_attachments.path`).
     */
    public function uploadForMedicalRecordAttachment(UploadedFile $file, int $medicalRecordId, int $uploaderId): string
    {
        $this->validate($file);
        $this->assertRealMimeTypeAllowed($file);
        $this->scanForMalware($file);

        $extension = strtolower($file->getClientOriginalExtension());
        $filename = Str::uuid().'.'.$extension;

        $path = $file->storeAs(
            "medical-records/{$medicalRecordId}/{$uploaderId}",
            $filename,
            $this->privateDisk()
        );

        if ($path === false || $path === null) {
            throw ValidationException::withMessages([
                'file' => 'Falha ao salvar o arquivo. Tente novamente.',
            ]);
        }

        return $path;
    }

    /**
     * Disk onde arquivos privados ficam. Em ordem de prioridade:
     *   1. PRIVATE_STORAGE_DISK (override explícito do operador).
     *   2. `s3` — apenas se o pacote league/flysystem-aws-s3-v3 estiver instalado
     *      E AWS_BUCKET configurado. Em staging/prod usaremos S3/MinIO real.
     *   3. `local` — disco privado por padrão neste projeto (root=storage/app/private,
     *      serve=true mas sem rota web exposta). NUNCA cair no disco `public`.
     *
     * O check de classe evita o bug "Class League\\Flysystem\\AwsS3V3\\... not found"
     * quando o operador colocou AWS_BUCKET no .env mas ainda não rodou
     * `composer require league/flysystem-aws-s3-v3`.
     */
    public function privateDisk(): string
    {
        $configured = env('PRIVATE_STORAGE_DISK');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $s3DriverInstalled = class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class);
        if (env('AWS_BUCKET') && $s3DriverInstalled) {
            return 's3';
        }

        return 'local';
    }

    /**
     * Validate file size and extension. Extension-only validation is intentional here
     * for fast rejection; real MIME inspection happens in assertRealMimeTypeAllowed().
     */
    private function validate(UploadedFile $file): void
    {
        if ($file->getSize() > $this->maxSize) {
            throw ValidationException::withMessages([
                'file' => 'File size exceeds 10MB limit',
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, $this->allowedTypes)) {
            throw ValidationException::withMessages([
                'file' => 'Invalid file type. Allowed: PDF, JPG, PNG, HEIC',
            ]);
        }
    }

    /**
     * Inspect the real file signature (magic bytes) via finfo and reject if it
     * doesn't match the allowlist. Protege contra upload malicioso com extensão
     * renomeada (ex: .php como .jpg).
     */
    private function assertRealMimeTypeAllowed(UploadedFile $file): void
    {
        $path = $file->getPathname();

        // getMimeType() do Symfony já usa finfo por baixo, mas reforçamos aqui para
        // ser explícito e não depender de versão.
        $detected = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path) ?: null;
                finfo_close($finfo);
            }
        }

        $detected = $detected ?: $file->getMimeType();

        if (! in_array($detected, $this->allowedMimeTypes, true)) {
            Log::warning('FileUploadService: rejected upload with mismatched MIME', [
                'detected_mime' => $detected,
                'client_ext' => strtolower($file->getClientOriginalExtension()),
                'client_name' => $file->getClientOriginalName(),
            ]);

            throw ValidationException::withMessages([
                'file' => 'Conteúdo do arquivo não corresponde a um PDF/imagem válido.',
            ]);
        }
    }

    /**
     * Hook de scan antivírus. Por padrão é no-op (MVP).
     *
     * TODO: integrar ClamAV via socket TCP (tipicamente `clamd` na porta 3310).
     * Quando CLAMAV_ENABLED=true:
     *   1. Abrir socket em CLAMAV_HOST:CLAMAV_PORT.
     *   2. Enviar comando INSTREAM com o conteúdo do arquivo.
     *   3. Se resposta contiver "FOUND", throw ValidationException.
     *   4. Se não conseguir conectar / timeout, bloquear upload (fail-closed)
     *      em vez de permitir (fail-open) — lembrar que LGPD pede diligência.
     */
    private function scanForMalware(UploadedFile $file): void
    {
        if (! filter_var(env('CLAMAV_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        // Placeholder: quando habilitado mas ainda não implementado, logamos e
        // (por segurança) rejeitamos o upload ao invés de deixar passar sem scan.
        Log::error('FileUploadService: CLAMAV_ENABLED=true mas scan ainda não implementado. Bloqueando upload por segurança.', [
            'file' => $file->getClientOriginalName(),
        ]);

        throw ValidationException::withMessages([
            'file' => 'Scan antivírus indisponível no momento. Tente novamente em instantes.',
        ]);
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
