<?php

namespace App\Models;

use App\Enums\GeneratedDocumentSignatureType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Um documento efetivamente emitido — contrato docs/gap-simplesvet/specs/
 * 15-modelos-documento-receituario-assinatura-spec.md. `body_html` é a cópia CONGELADA do
 * template no momento da emissão (regra de negócio 3) — nunca reaponta para o template.
 */
class GeneratedDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'document_template_id',
        'pet_id',
        'client_id',
        'medical_record_id',
        'issued_by',
        'issued_at',
        'body_html',
        'pdf_path',
        'signature_type',
        'signature_ref',
        'signed_at',
        'hash',
        'verification_code',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'signed_at' => 'datetime',
            'signature_type' => GeneratedDocumentSignatureType::class,
        ];
    }

    public function documentTemplate(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class);
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }
}
