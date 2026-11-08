<?php

namespace App\Models;

use App\Enums\FiscalCertificateMatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Certificado A1 da organização — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md
 * §3. Os dois `*_ciphertext` só são escritos e lidos por `FiscalCertificateVault` (cifra com
 * contexto da organização); ficam em `$hidden` e fora do `$fillable` para nunca vazarem por
 * serialização nem por atribuição em massa.
 */
class FiscalCertificate extends Model
{
    protected $fillable = [
        'subject_document',
        'subject_name',
        'issuer_name',
        'serial_number',
        'fingerprint',
        'cnpj_match',
        'valid_from',
        'valid_to',
        'uploaded_by',
    ];

    protected $hidden = ['pfx_ciphertext', 'password_ciphertext'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cnpj_match' => FiscalCertificateMatch::class,
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isExpired(): bool
    {
        return $this->valid_to->isPast();
    }

    /** Dias inteiros até vencer (negativo depois de vencido) — aviso na tela de configuração. */
    public function daysToExpire(): int
    {
        return (int) floor(now()->diffInDays($this->valid_to, false));
    }
}
