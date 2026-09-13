<?php

namespace App\Support\Registration;

use App\Enums\Registration\ProfessionalServiceItem;
use App\Enums\ServiceCategory;

/**
 * Resolve um valor de `services_offered` (o que o profissional marcou) para a
 * `ServiceCategory` que a matriz de permissão (`ProfessionalCapabilityRegistry`) sabe
 * avaliar — ponte entre o catálogo granular (`ProfessionalServiceItem`, o que o front
 * mostra) e a categoria (o que a API valida).
 *
 * Aceita DOIS formatos, sem apagar nenhum: cadastro antigo grava direto o valor de
 * categoria (`"consultation"`, `"surgery"`); cadastro novo grava o valor granular do
 * catálogo (`"xray"`, `"deworming"`). Categoria tem prioridade — um valor que já é uma
 * `ServiceCategory` válida nunca passa pelo catálogo granular. É o que preserva o dado
 * legado e resolve a única colisão de nome do catálogo (`"behavioral"` — ver nota em
 * `ProfessionalServiceItem`).
 */
final class ServiceCatalog
{
    public static function categoryFor(string $value): ?ServiceCategory
    {
        return ServiceCategory::tryFrom($value) ?? ProfessionalServiceItem::tryFrom($value)?->category();
    }

    public static function isKnownValue(string $value): bool
    {
        return self::categoryFor($value) !== null;
    }

    /**
     * Payload consumível por `GET /register/professional-schema`: todo item granular do
     * catálogo com rótulo pt-BR e a `ServiceCategory` que ele resolve — fonte única para o
     * front parar de manter `constants/serviceCategoryMap.js` em paralelo (risco medido em
     * 2026-09-13: as duas cópias já haviam divergido em `"microchip"`, categoria
     * inexistente no backend).
     *
     * @return list<array{value: string, label: string, category: string}>
     */
    public static function itemsPayload(): array
    {
        return array_map(
            static fn (ProfessionalServiceItem $item): array => [
                'value' => $item->value,
                'label' => $item->label(),
                'category' => $item->category()->value,
            ],
            ProfessionalServiceItem::cases(),
        );
    }
}
