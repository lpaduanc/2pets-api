<?php

namespace App\Services\Document;

use App\Models\GeneratedDocument;

/**
 * `GET public/documents/verify/{code}` — contrato docs/gap-simplesvet/specs/
 * 15-modelos-documento-receituario-assinatura-spec.md, regra de negócio 6: confirma só
 * "documento válido, emitido por X em Y para o animal Z", NUNCA diagnóstico/prescrição/dado
 * de saúde do `body_html`.
 */
final class DocumentVerificationService
{
    /**
     * @return array{valid: bool, issued_at?: string, issued_by?: string, pet_name?: string, document_kind?: string}
     */
    public function verify(string $code): array
    {
        $document = GeneratedDocument::query()
            ->where('verification_code', $code)
            ->with(['issuedBy', 'pet', 'documentTemplate'])
            ->first();

        if ($document === null) {
            return ['valid' => false];
        }

        return [
            'valid' => true,
            'issued_at' => $document->issued_at->toIso8601String(),
            'issued_by' => $document->issuedBy->name,
            'pet_name' => $document->pet->name,
            'document_kind' => $document->documentTemplate->kind->value,
        ];
    }
}
