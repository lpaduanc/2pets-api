<?php

namespace App\Services\Medical;

use App\Enums\GeneratedDocumentSignatureType;
use App\Models\Prescription;
use Illuminate\Support\Str;

/**
 * `POST prescriptions/{id}/sign` — contrato docs/gap-simplesvet/specs/
 * 15-modelos-documento-receituario-assinatura-spec.md. Assinatura eletrônica SIMPLES (nome +
 * CRMV + timestamp + hash do conteúdo) — não é certificado ICP-Brasil (regra de negócio 4,
 * a UI precisa nomear com precisão o que está sendo entregue).
 *
 * Idempotente: chamar de novo numa prescrição já assinada devolve o MESMO
 * `verification_code`/`hash`, nunca gera um segundo.
 */
final class PrescriptionSignatureService
{
    public function sign(Prescription $prescription): Prescription
    {
        if ($prescription->isSigned()) {
            return $prescription;
        }

        $signedAt = now();
        $hash = hash('sha256', $prescription->id.'|'.$prescription->issued_at?->toIso8601String().'|'.$signedAt->toIso8601String());

        $prescription->forceFill([
            'signature_type' => GeneratedDocumentSignatureType::SIMPLE_ELECTRONIC->value,
            'signed_at' => $signedAt,
            'hash' => $hash,
            'verification_code' => Str::upper(Str::random(10)),
        ])->save();

        return $prescription->fresh();
    }
}
