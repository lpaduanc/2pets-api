<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Anexo (imagem/PDF) de um prontuário. Disco sempre privado — nunca URL pública direta.
 * Download via rota autorizada, mesmo padrão de `ExamImage`/`ExamController::downloadImage`.
 */
class MedicalRecordAttachment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'medical_record_id',
        'path',
        'original_name',
        'mime',
        'size',
        'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Apaga o blob físico do disco privado. Seguro chamar mesmo se a linha já foi
     * soft-deleted — só o arquivo em disco vai embora.
     */
    public function deletePhysicalFile(string $disk): bool
    {
        $storage = Storage::disk($disk);

        if ($storage->exists($this->path)) {
            return $storage->delete($this->path);
        }

        return false;
    }
}
