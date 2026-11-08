<?php

namespace Tests\Feature\Fiscal;

use App\Models\FiscalDocument;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Fiscal\Concerns\BuildsSefazScenario;
use Tests\TestCase;

/**
 * Download do XML autorizado e do DANFE — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §8: `fiscal-documents.view` + escopo + só o dono, stream do
 * disco privado, download auditado; documento simulado não tem arquivo.
 */
class FiscalDocumentFileDownloadTest extends TestCase
{
    use BuildsSefazScenario;
    use RefreshDatabase;

    private array $document;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSefazClinic();
        $this->document = $this->issueSale($this->paidProductSale([[$this->makeProduct(), 1]]))->assertCreated()->json('data.0');
    }

    public function test_the_owner_downloads_the_authorized_xml_and_the_danfce(): void
    {
        $xml = $this->actingAs($this->owner, 'sanctum')->get($this->url('xml'))->assertOk();
        $xml->assertHeader('Content-Type', 'application/xml');
        $this->assertStringContainsString($this->document['access_key'].'.xml', (string) $xml->headers->get('Content-Disposition'));
        $this->assertStringContainsString('<nfeProc', $xml->streamedContent());

        $pdf = $this->actingAs($this->owner, 'sanctum')->get($this->url('pdf'))->assertOk();
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->streamedContent());

        $this->assertSame(2, Activity::query()->where('event', 'fiscal_document.downloaded')->count());
    }

    public function test_staff_without_the_owner_role_cannot_download(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')->getJson($this->url('xml'))->assertForbidden();
        $this->actingAs($this->groomer, 'sanctum')->getJson($this->url('pdf'))->assertForbidden();
    }

    public function test_another_organization_cannot_reach_the_document(): void
    {
        $stranger = \App\Models\User::factory()->professional()->create();
        \App\Models\OrganizationMember::factory()->owner()->for(Organization::factory()->petshop()->create(), 'organization')->create(['user_id' => $stranger->id]);

        $this->actingAs($stranger, 'sanctum')->getJson($this->url('xml'))->assertNotFound();
    }

    public function test_a_simulated_document_has_no_file_to_download(): void
    {
        FiscalDocument::query()->whereKey($this->document['id'])->update(['xml_path' => null, 'pdf_path' => null]);

        $this->actingAs($this->owner, 'sanctum')->getJson($this->url('xml'))->assertNotFound();
        $this->actingAs($this->owner, 'sanctum')->getJson($this->url('pdf'))->assertNotFound();
    }

    private function url(string $type): string
    {
        return "/api/professional/fiscal-documents/{$this->document['id']}/{$type}";
    }
}
