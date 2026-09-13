<?php

namespace App\Support\Registration;

use App\Enums\Registration\ClinicalEquipment;
use App\Enums\ServiceCategory;

/**
 * Regra "não se oferece serviço cujo equipamento correspondente não foi declarado"
 * (`docs/equipamento-vet-volante-e-marketplace-b2b.md` §2.2) — vale para os três tipos que
 * têm `imaging`/`laboratory` no catálogo (`vet`, `clinic`, `laboratory`; ver
 * `ProfessionalCapabilityDefinitions`). O mapa é dado, não `if`: um serviço novo com a mesma
 * exigência só precisa de uma linha aqui.
 *
 * Consumida por `HasProfessionalCapabilityRules` (validação cruzada no Form Request) e por
 * `ProfessionalSchemaBuilder` (o front precisa do mapa para desabilitar o serviço na tela
 * enquanto o equipamento não estiver marcado).
 */
final class ServiceEquipmentDependency
{
    /** @return array<string, list<ClinicalEquipment>> */
    private static function map(): array
    {
        return [
            ServiceCategory::IMAGING->value => [
                ClinicalEquipment::XRAY_MACHINE,
                ClinicalEquipment::ULTRASOUND_MACHINE,
                ClinicalEquipment::ECG_MACHINE,
            ],
            ServiceCategory::LABORATORY->value => [
                ClinicalEquipment::LABORATORY,
                ClinicalEquipment::POINT_OF_CARE_ANALYZER,
            ],
        ];
    }

    /** @return list<ClinicalEquipment> */
    public static function requiredEquipmentFor(ServiceCategory $category): array
    {
        return self::map()[$category->value] ?? [];
    }

    /** @param list<ClinicalEquipment> $declaredEquipment */
    public static function isSatisfiedBy(ServiceCategory $category, array $declaredEquipment): bool
    {
        $required = self::requiredEquipmentFor($category);

        if ($required === []) {
            return true;
        }

        foreach ($required as $item) {
            if (in_array($item, $declaredEquipment, true)) {
                return true;
            }
        }

        return false;
    }

    public static function missingEquipmentMessage(ServiceCategory $category): string
    {
        $labels = array_map(
            fn (ClinicalEquipment $item): string => $item->label(),
            self::requiredEquipmentFor($category),
        );

        return sprintf(
            'Para oferecer "%s" é necessário declarar ao menos um destes equipamentos: %s.',
            $category->label(),
            implode(' ou ', $labels),
        );
    }

    /**
     * Payload consumível pelo front (`ProfessionalSchemaBuilder`): categoria de serviço →
     * valores de equipamento que satisfazem a exigência.
     *
     * @return array<string, list<string>>
     */
    public static function toSchemaPayload(): array
    {
        $payload = [];

        foreach (self::map() as $serviceCategoryValue => $equipmentItems) {
            $payload[$serviceCategoryValue] = array_map(
                fn (ClinicalEquipment $item): string => $item->value,
                $equipmentItems,
            );
        }

        return $payload;
    }
}
