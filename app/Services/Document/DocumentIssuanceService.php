<?php

namespace App\Services\Document;

use App\Exceptions\Document\ClinicalIssuerRequiredException;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Organization;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Emissão de documento a partir de um `DocumentTemplate` — contrato docs/gap-simplesvet/specs/
 * 15-modelos-documento-receituario-assinatura-spec.md, regras de negócio 1/3/6.
 */
final class DocumentIssuanceService
{
    public function __construct(private readonly DocumentTemplateRenderer $renderer) {}

    /**
     * @throws ClinicalIssuerRequiredException Template clínico emitido por quem não pratica ato clínico.
     */
    public function issue(DocumentTemplate $template, Pet $pet, User $issuedBy, ?int $medicalRecordId = null): GeneratedDocument
    {
        $this->assertCanIssue($template, $issuedBy);

        $organizationId = $issuedBy->activeOrganizationId();

        return DB::transaction(function () use ($template, $pet, $issuedBy, $medicalRecordId, $organizationId): GeneratedDocument {
            $bodyHtml = $this->renderer->render(
                $template->body_html,
                $pet,
                $issuedBy,
                $organizationId !== null ? Organization::find($organizationId) : null,
                now(),
            );

            return GeneratedDocument::create([
                'organization_id' => $organizationId,
                'document_template_id' => $template->id,
                'pet_id' => $pet->id,
                'client_id' => $pet->user_id,
                'medical_record_id' => $medicalRecordId,
                'issued_by' => $issuedBy->id,
                'issued_at' => now(),
                'body_html' => $bodyHtml,
                'verification_code' => Str::upper(Str::random(10)),
                'hash' => hash('sha256', $bodyHtml.now()->toIso8601String()),
            ]);
        });
    }

    private function assertCanIssue(DocumentTemplate $template, User $issuedBy): void
    {
        if ($template->kind->requiresClinicalIssuer() && ! $issuedBy->isVeterinarian()) {
            throw new ClinicalIssuerRequiredException;
        }
    }
}
