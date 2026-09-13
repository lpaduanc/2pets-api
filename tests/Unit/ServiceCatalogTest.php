<?php

namespace Tests\Unit;

use App\Enums\Registration\ProfessionalServiceItem;
use App\Enums\ServiceCategory;
use App\Support\Registration\ServiceCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `ServiceCatalog::categoryFor()` é a correção de P0 2026-09-13: o catálogo granular que
 * `2pets-app/src/constants/professionalOptions.js` (`SERVICES`) envia em `services_offered`
 * precisa resolver para uma `ServiceCategory` antes de a matriz de permissão avaliar. Sem
 * banco (nenhuma migration/`RefreshDatabase`), mas precisa do app Laravel de pé — desde a
 * i18n do schema de cadastro, `ProfessionalServiceItem::label()` resolve por tradução
 * (`__()`), que exige o container. Por isso estende `Tests\TestCase`, não
 * `PHPUnit\Framework\TestCase` puro (`test_items_payload_has_one_entry_per_case_with_a_matching_category`
 * é quem chama `label()` aqui).
 *
 * Nota sobre a "trava contra o catálogo do front ganhar item sem categoria": o container do
 * `backend` só monta `2pets-api/` (`docker-compose.yml`), sem acesso ao arquivo do front —
 * não há como este teste ler `professionalOptions.js` de verdade. O que ele garante é a
 * integridade do lado do backend: todo valor que HOJE existe no catálogo do front (lista
 * espelhada abaixo) resolve para uma categoria. Se o front adicionar um item novo, só um
 * teste no próprio `2pets-app` (ou a migração completa do catálogo para o schema, que
 * eliminaria a cópia local) pode pegar a divergência — ver relato da tarefa.
 */
class ServiceCatalogTest extends TestCase
{
    #[DataProvider('granularValueProvider')]
    public function test_it_resolves_every_current_frontend_catalog_value_to_a_category(string $value, ServiceCategory $expected): void
    {
        $this->assertSame($expected, ServiceCatalog::categoryFor($value));
    }

    /** @return array<string, array{string, ServiceCategory}> */
    public static function granularValueProvider(): array
    {
        return [
            'deworming' => ['deworming', ServiceCategory::VACCINATION],
            'microchip' => ['microchip', ServiceCategory::OTHER],
            'health_certificate' => ['health_certificate', ServiceCategory::CONSULTATION],
            'pre_anesthetic' => ['pre_anesthetic', ServiceCategory::CONSULTATION],
            'nutrition_consultation' => ['nutrition_consultation', ServiceCategory::NUTRITION],
            'emergency_care' => ['emergency_care', ServiceCategory::EMERGENCY],
            'behavioral_consultation' => ['behavioral_consultation', ServiceCategory::BEHAVIORAL],
            'castration' => ['castration', ServiceCategory::SURGERY],
            'surgery_general' => ['surgery_general', ServiceCategory::SURGERY],
            'emergency_surgery' => ['emergency_surgery', ServiceCategory::SURGERY],
            'orthopedic_surgery' => ['orthopedic_surgery', ServiceCategory::SURGERY],
            'soft_tissue' => ['soft_tissue', ServiceCategory::SURGERY],
            'dental_surgery' => ['dental_surgery', ServiceCategory::SURGERY],
            'xray' => ['xray', ServiceCategory::IMAGING],
            'ultrasound' => ['ultrasound', ServiceCategory::IMAGING],
            'ecg' => ['ecg', ServiceCategory::IMAGING],
            'endoscopy' => ['endoscopy', ServiceCategory::IMAGING],
            'biopsy' => ['biopsy', ServiceCategory::IMAGING],
            'blood_test' => ['blood_test', ServiceCategory::LABORATORY],
            'urine_test' => ['urine_test', ServiceCategory::LABORATORY],
            'fecal_test' => ['fecal_test', ServiceCategory::LABORATORY],
            'icu' => ['icu', ServiceCategory::HOSPITALIZATION],
            'physiotherapy' => ['physiotherapy', ServiceCategory::REHABILITATION],
            'acupuncture' => ['acupuncture', ServiceCategory::REHABILITATION],
            'post_surgery' => ['post_surgery', ServiceCategory::REHABILITATION],
            'bath' => ['bath', ServiceCategory::GROOMING],
            'haircut' => ['haircut', ServiceCategory::GROOMING],
            'hygienic_grooming' => ['hygienic_grooming', ServiceCategory::GROOMING],
            'nail_trim' => ['nail_trim', ServiceCategory::GROOMING],
            'ear_cleaning' => ['ear_cleaning', ServiceCategory::GROOMING],
            'teeth_cleaning' => ['teeth_cleaning', ServiceCategory::GROOMING],
            'pet_spa' => ['pet_spa', ServiceCategory::GROOMING],
            'basic_training' => ['basic_training', ServiceCategory::TRAINING],
            'socialization' => ['socialization', ServiceCategory::TRAINING],
            'agility' => ['agility', ServiceCategory::TRAINING],
            'guard_training' => ['guard_training', ServiceCategory::TRAINING],
            'daycare' => ['daycare', ServiceCategory::BOARDING],
            'boarding_long' => ['boarding_long', ServiceCategory::BOARDING],
            'cat_hotel' => ['cat_hotel', ServiceCategory::BOARDING],
            'dog_hotel' => ['dog_hotel', ServiceCategory::BOARDING],
        ];
    }

    /**
     * Os itens do catálogo cujo valor bruto já É idêntico a uma `ServiceCategory`
     * (`"consultation"`, `"vaccination"`, `"hospitalization"`, `"boarding"`) não precisam de
     * `case` em `ProfessionalServiceItem` — resolvem direto. Prova de que a prioridade é
     * categoria primeiro, catálogo granular depois (`ServiceCatalog::categoryFor()`).
     */
    #[DataProvider('legacyCategoryValueProvider')]
    public function test_it_resolves_a_legacy_category_value_directly_without_the_granular_catalog(string $value, ServiceCategory $expected): void
    {
        $this->assertSame($expected, ServiceCatalog::categoryFor($value));
    }

    /** @return array<string, array{string, ServiceCategory}> */
    public static function legacyCategoryValueProvider(): array
    {
        return [
            'consultation' => ['consultation', ServiceCategory::CONSULTATION],
            'vaccination' => ['vaccination', ServiceCategory::VACCINATION],
            'hospitalization' => ['hospitalization', ServiceCategory::HOSPITALIZATION],
            'boarding' => ['boarding', ServiceCategory::BOARDING],
            // Colisão deliberada (ver `ProfessionalServiceItem`): o item granular
            // "Comportamental" do catálogo de Adestramento usa a MESMA string
            // (`"behavioral"`) que a categoria de medicina comportamental. Categoria vence
            // — preserva o cadastro legado de `vet`/`clinic`, ao custo de o item granular de
            // adestramento continuar irrepresentável enquanto tiver este nome (sinalizado no
            // relato da tarefa para pet-business-specialist/frontend-specialist).
            'behavioral (colisão, categoria vence)' => ['behavioral', ServiceCategory::BEHAVIORAL],
        ];
    }

    public function test_it_returns_null_for_an_unknown_value(): void
    {
        $this->assertNull(ServiceCatalog::categoryFor('this_service_does_not_exist'));
        $this->assertFalse(ServiceCatalog::isKnownValue('this_service_does_not_exist'));
    }

    public function test_items_payload_has_one_entry_per_case_with_a_matching_category(): void
    {
        $payload = ServiceCatalog::itemsPayload();

        $this->assertCount(count(ProfessionalServiceItem::cases()), $payload);

        foreach ($payload as $entry) {
            $item = ProfessionalServiceItem::from($entry['value']);
            $this->assertSame($item->label(), $entry['label']);
            $this->assertSame($item->category()->value, $entry['category']);
        }
    }
}
