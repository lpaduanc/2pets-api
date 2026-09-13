<?php

namespace Tests\Unit;

use App\Enums\ProfessionalType;
use App\Support\Registration\ProfessionalCapabilityFieldExtractor;
use App\Support\Registration\ProfessionalCapabilityRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Segunda camada de defesa contra o mesmo bug de `HasProfessionalCapabilityRules`/
 * `ProhibitedCapabilityValue`: mesmo que `false` passe a validação para um campo não
 * aplicável ao tipo, a coluna nunca pode ser escrita. Dado puro (`ProfessionalTypeCapabilities`
 * vem da registry real, sem banco) — roda como Unit.
 */
class ProfessionalCapabilityFieldExtractorTest extends TestCase
{
    public function test_extract_forces_null_for_a_facility_not_allowed_for_the_type(): void
    {
        $vet = ProfessionalCapabilityRegistry::for(ProfessionalType::VET);

        $result = ProfessionalCapabilityFieldExtractor::extract(['parking_available' => false], $vet);

        $this->assertNull($result['parking_available']);
    }

    public function test_extract_keeps_the_value_of_an_allowed_field(): void
    {
        $petshop = ProfessionalCapabilityRegistry::for(ProfessionalType::PETSHOP);

        $result = ProfessionalCapabilityFieldExtractor::extract(['accepts_credit_card' => true], $petshop);

        $this->assertTrue($result['accepts_credit_card']);
    }

    public function test_scrub_for_patch_does_not_reintroduce_a_key_the_client_never_sent(): void
    {
        $vet = ProfessionalCapabilityRegistry::for(ProfessionalType::VET);

        $result = ProfessionalCapabilityFieldExtractor::scrubForPatch(['business_name' => 'Novo Nome'], $vet);

        $this->assertSame(['business_name' => 'Novo Nome'], $result);
    }

    public function test_scrub_for_patch_neutralizes_only_the_key_the_client_actually_sent(): void
    {
        $vet = ProfessionalCapabilityRegistry::for(ProfessionalType::VET);

        $result = ProfessionalCapabilityFieldExtractor::scrubForPatch([
            'business_name' => 'Novo Nome',
            'parking_available' => false,
        ], $vet);

        $this->assertSame('Novo Nome', $result['business_name']);
        $this->assertNull($result['parking_available']);
    }

    public function test_scrub_for_patch_leaves_an_allowed_present_value_untouched(): void
    {
        $petshop = ProfessionalCapabilityRegistry::for(ProfessionalType::PETSHOP);

        $result = ProfessionalCapabilityFieldExtractor::scrubForPatch(['accepts_credit_card' => true], $petshop);

        $this->assertTrue($result['accepts_credit_card']);
    }
}
