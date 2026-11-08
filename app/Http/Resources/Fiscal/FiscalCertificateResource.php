<?php

namespace App\Http\Resources\Fiscal;

use App\Models\FiscalCertificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Metadados do A1 — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md §3.4. Só o
 * que já é público no certificado que acompanha toda NF-e assinada (titular, CNPJ, AC, validade,
 * impressão digital). NUNCA o `.pfx`, a senha, os `*_ciphertext` nem `certificate_ref`.
 *
 * @mixin FiscalCertificate
 */
class FiscalCertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $daysToExpire = $this->daysToExpire();

        return [
            'subject_name' => $this->subject_name,
            'subject_document' => $this->subject_document,
            'cnpj_match' => $this->cnpj_match->value,
            'issuer_name' => $this->issuer_name,
            'serial_number' => $this->serial_number,
            'fingerprint_sha256' => $this->fingerprint,
            'valid_from' => $this->valid_from->toIso8601String(),
            'valid_to' => $this->valid_to->toIso8601String(),
            'days_to_expire' => $daysToExpire,
            'is_expired' => $this->isExpired(),
            'expires_soon' => ! $this->isExpired() && $daysToExpire <= (int) config('fiscal.certificate.warning_days'),
            'uploaded_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
