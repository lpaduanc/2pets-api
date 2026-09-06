<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class ExamImage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'exam_id',
        'uploader_id',
        'disk',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'image_type',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    /**
     * Resolve the storage disk this image lives on. Falls back to `local` (private)
     * when the column wasn't populated — matches pre-wave-2.1 rows.
     */
    public function diskName(): string
    {
        return $this->disk ?: 'local';
    }

    /**
     * Delete the physical file from its disk. Safe to call even if the row was
     * already soft-deleted — only the blob goes.
     */
    public function deletePhysicalFile(): bool
    {
        if (empty($this->file_path)) {
            return false;
        }

        $disk = Storage::disk($this->diskName());
        if ($disk->exists($this->file_path)) {
            return $disk->delete($this->file_path);
        }

        return false;
    }
}
