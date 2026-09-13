<?php

namespace Tests\Unit;

use App\Enums\Registration\ClinicalEquipment;
use App\Enums\ServiceCategory;
use App\Support\Registration\ServiceEquipmentDependency;
use Tests\TestCase;

/**
 * Regra "não se oferece serviço cujo equipamento correspondente não foi declarado"
 * (`docs/equipamento-vet-volante-e-marketplace-b2b.md` §2.2). Sem banco (nenhuma
 * migration/`RefreshDatabase`), mas precisa do app Laravel de pé: desde a i18n do schema de
 * cadastro, `ClinicalEquipment::label()`/`ServiceCategory::label()` resolvem por tradução
 * (`__()`), usados por `missingEquipmentMessage()`. Por isso estende `Tests\TestCase`, não
 * `PHPUnit\Framework\TestCase` puro.
 */
class ServiceEquipmentDependencyTest extends TestCase
{
    public function test_imaging_is_satisfied_by_any_of_the_three_imaging_devices(): void
    {
        $this->assertTrue(ServiceEquipmentDependency::isSatisfiedBy(ServiceCategory::IMAGING, [ClinicalEquipment::ULTRASOUND_MACHINE]));
        $this->assertTrue(ServiceEquipmentDependency::isSatisfiedBy(ServiceCategory::IMAGING, [ClinicalEquipment::XRAY_MACHINE]));
        $this->assertTrue(ServiceEquipmentDependency::isSatisfiedBy(ServiceCategory::IMAGING, [ClinicalEquipment::ECG_MACHINE]));
    }

    public function test_imaging_is_not_satisfied_by_unrelated_equipment(): void
    {
        $this->assertFalse(ServiceEquipmentDependency::isSatisfiedBy(ServiceCategory::IMAGING, [ClinicalEquipment::VASCULAR_DOPPLER]));
        $this->assertFalse(ServiceEquipmentDependency::isSatisfiedBy(ServiceCategory::IMAGING, []));
    }

    public function test_laboratory_is_satisfied_by_lab_or_point_of_care_analyzer(): void
    {
        $this->assertTrue(ServiceEquipmentDependency::isSatisfiedBy(ServiceCategory::LABORATORY, [ClinicalEquipment::LABORATORY]));
        $this->assertTrue(ServiceEquipmentDependency::isSatisfiedBy(ServiceCategory::LABORATORY, [ClinicalEquipment::POINT_OF_CARE_ANALYZER]));
        $this->assertFalse(ServiceEquipmentDependency::isSatisfiedBy(ServiceCategory::LABORATORY, [ClinicalEquipment::XRAY_MACHINE]));
    }

    /**
     * Serviço sem dependência mapeada (ex.: `consultation`) nunca exige equipamento —
     * a regra é opt-in por linha do mapa, não um "toda categoria precisa de aparelho".
     */
    public function test_service_category_without_a_mapped_dependency_is_always_satisfied(): void
    {
        $this->assertTrue(ServiceEquipmentDependency::isSatisfiedBy(ServiceCategory::CONSULTATION, []));
    }

    public function test_missing_equipment_message_names_the_service_and_the_valid_alternatives(): void
    {
        $message = ServiceEquipmentDependency::missingEquipmentMessage(ServiceCategory::IMAGING);

        $this->assertStringContainsString('Exames de Imagem', $message);
        $this->assertStringContainsString('Aparelho de Ultrassom', $message);
    }

    public function test_schema_payload_maps_service_category_values_to_equipment_values(): void
    {
        $payload = ServiceEquipmentDependency::toSchemaPayload();

        $this->assertSame(
            ['xray_machine', 'ultrasound_machine', 'ecg_machine'],
            $payload['imaging'],
        );
        $this->assertSame(['laboratory', 'point_of_care_analyzer'], $payload['laboratory']);
    }
}
