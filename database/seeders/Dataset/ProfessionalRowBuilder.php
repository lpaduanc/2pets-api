<?php

namespace Database\Seeders\Dataset;

use App\Enums\ProfessionalType;
use App\Enums\ServiceCategory;
use App\Support\Registration\ProfessionalCapabilityRegistry;

/**
 * Monta as linhas de `users`, `professionals` e `services` de UM profissional a partir de uma
 * `CoherentOffering` já planejada. Não decide oferta nenhuma — só traduz a oferta em colunas.
 *
 * `latitude`/`longitude` saem daqui, mas `location` (geography) NÃO: a coluna PostGIS é
 * escrita por SQL cru em `ProfessionalDatasetSeeder`, na mesma transação, porque o Eloquent
 * e o query builder não sabem produzir `ST_MakePoint`.
 */
final class ProfessionalRowBuilder
{
    /**
     * @param  array{city: string, state: string, lat: float, lng: float, spread: float, weight: int, zip: string}  $place
     * @return array<string, mixed>
     */
    public static function userRow(int $sequence, ProfessionalType $type, string $displayName, array $place, string $passwordHash): array
    {
        $now = now();

        return [
            'name' => $displayName,
            'email' => sprintf('%s%d@2pets.dev', $type->value, $sequence),
            'password' => $passwordHash,
            'role' => 'professional',
            'user_type' => $type->value,
            'phone' => '11'.mt_rand(900000000, 999999999),
            'address' => 'Rua '.DatasetRandom::pick(BusinessNamePools::LAST_NAMES),
            'number' => (string) mt_rand(10, 2400),
            'neighborhood' => DatasetRandom::pick(BusinessNamePools::QUALIFIERS),
            'city' => $place['city'],
            'state' => $place['state'],
            'zip_code' => $place['zip'].'-'.str_pad((string) mt_rand(0, 999), 3, '0', STR_PAD_LEFT),
            'latitude' => round($place['lat'] + DatasetRandom::jitter($place['spread']), 8),
            'longitude' => round($place['lng'] + DatasetRandom::jitter($place['spread']), 8),
            'email_verified_at' => $now,
            'email_verified' => true,
            'profile_completed' => true,
            'registration_status' => 'approved',
            'is_suspended' => false,
            'consent_search_visibility' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * `$sequence` precisa ser GLOBAL (único entre todos os tipos), não o índice dentro do
     * tipo: `professionals.cnpj` tem índice único, e um contador que reinicia a cada tipo faz
     * a primeira clínica colidir com o primeiro laboratório. Ver
     * `ProfessionalDatasetSeeder::globalSequence()`.
     *
     * @return array<string, mixed>
     */
    public static function professionalRow(int $userId, int $sequence, ProfessionalType $type, ?string $businessName, CoherentOffering $offering): array
    {
        $capabilities = ProfessionalCapabilityRegistry::for($type);
        $now = now();

        return [
            'user_id' => $userId,
            'professional_type' => $type->value,
            'business_name' => $businessName,
            'cnpj' => $capabilities->identification === 'cnpj' ? self::syntheticCnpj($sequence) : null,
            'crmv' => $capabilities->requiresCrmv ? (string) (10000 + $sequence) : null,
            'crmv_state' => $capabilities->requiresCrmv ? 'SP' : null,
            'technical_responsible_name' => $capabilities->requiresTechnicalResponsible ? self::personName() : null,
            'technical_responsible_crmv' => $capabilities->requiresTechnicalResponsible ? (string) (40000 + $sequence) : null,
            'technical_responsible_crmv_state' => $capabilities->requiresTechnicalResponsible ? 'SP' : null,
            'specialties' => json_encode($offering->specialties, JSON_UNESCAPED_UNICODE),
            'services_offered' => json_encode($offering->serviceItemValues(), JSON_UNESCAPED_UNICODE),
            'equipment' => json_encode($offering->equipmentValues(), JSON_UNESCAPED_UNICODE),
            'species_served' => json_encode($offering->species, JSON_UNESCAPED_UNICODE),
            'description' => ProfessionalDescription::for($type, $offering),
            'service_radius_km' => $capabilities->serviceRadius === null ? null : mt_rand(5, 40),
            'experience_years' => mt_rand(1, 25),
            'average_rating' => number_format(mt_rand(300, 500) / 100, 2, '.', ''),
            'total_reviews' => mt_rand(0, 320),
            'is_featured' => DatasetRandom::chance(25),
            'is_crmv_verified' => $capabilities->requiresCrmv && DatasetRandom::chance(2),
            'emergency_available' => $offering->offers(ServiceCategory::EMERGENCY),
            'emergency_24h' => $offering->offers(ServiceCategory::EMERGENCY) && DatasetRandom::chance(3),
            'accepts_credit_card' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Uma linha de `services` por item de serviço planejado, mais uma por categoria que não
     * tem item granular no catálogo (`consultation`, `vaccination`, `boarding`,
     * `hospitalization`) — nesses casos o nome é o rótulo da própria categoria.
     *
     * @return list<array<string, mixed>>
     */
    public static function serviceRows(int $userId, CoherentOffering $offering): array
    {
        $rows = [];

        foreach ($offering->serviceItems as $item) {
            $rows[] = self::serviceRow($userId, $item->category(), $item->label());
        }

        foreach ($offering->categories as $category) {
            if ($offering->hasItemsIn($category)) {
                continue;
            }

            $rows[] = self::serviceRow($userId, $category, $category->label());
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serviceRow(int $userId, ServiceCategory $category, string $name): array
    {
        [$minPrice, $maxPrice] = ServicePricing::priceRangeFor($category);
        $now = now();

        return [
            'professional_id' => $userId,
            'name' => $name,
            'description' => $name.' com agendamento pela plataforma 2pets.',
            'category' => $category->value,
            'duration' => ServicePricing::durationFor($category),
            'price' => DatasetRandom::money($minPrice, $maxPrice),
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    public static function personName(): string
    {
        return DatasetRandom::pick(BusinessNamePools::FIRST_NAMES).' '.DatasetRandom::pick(BusinessNamePools::LAST_NAMES);
    }

    /** CNPJ sintético só de dígitos, como a coluna exige — nunca um documento real. */
    private static function syntheticCnpj(int $sequence): string
    {
        return str_pad((string) (10000000000000 + $sequence), 14, '0', STR_PAD_LEFT);
    }
}
