<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\URL;

class Document extends Model
{
    use SoftDeletes;

    /**
     * TTL of the signed URL returned by `signedFileUrl()`. Kept short because the
     * file is a sensitive personal document (CRMV/RG/diploma, LGPD art. 5º II) and
     * the signature is the ONLY access control once the link leaves the server —
     * see `DocumentResource::documentUrl()` for where authorization is checked.
     */
    public const FILE_URL_TTL_MINUTES = 5;

    protected $fillable = [
        'user_id',
        'document_type',
        'file_name',
        'file_path',
        'file_type',
        'file_size',
        'original_name',
        'verification_status',
        'verified_by',
        'verified_at',
        'verification_notes',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'verified_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Short-lived, tamper-proof URL to preview/download the file — never the
     * disk's raw public path. Caller is responsible for checking `DocumentPolicy`
     * before minting one; the signature itself is what protects it afterwards.
     */
    public function signedFileUrl(): string
    {
        return URL::temporarySignedRoute(
            'documents.file',
            now()->addMinutes(self::FILE_URL_TTL_MINUTES),
            ['document' => $this->id]
        );
    }
}
