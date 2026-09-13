<?php

namespace App\Enums\Registration;

use App\Enums\ServiceCategory;

/**
 * Catálogo granular de serviços (`2pets-app/src/constants/professionalOptions.js`,
 * `SERVICES`) — o item que o profissional REALMENTE marca na tela ("Vermifugação",
 * "Raio-X", "Castração"), não a `ServiceCategory` que o agrupa. Fonte única desde a
 * correção de P0 de 2026-09-13: até então só `ServiceCategory` era aceita em
 * `services_offered`, e o catálogo granular inteiro (90 itens) tomava 422 no cadastro.
 *
 * `category()` é a mesma categoria que `2pets-app/src/constants/serviceCategoryMap.js`
 * (`SERVICE_ITEM_CATEGORIES`) já atribuía a cada item — este enum é a versão canônica no
 * backend; o front deve consumir via `GET /register/professional-schema`
 * (`service_items`), não manter a cópia local (ver `ServiceCatalog::itemsPayload()`).
 *
 * Três exclusões deliberadas do catálogo do front, sem `case` aqui:
 * - `consultation`, `vaccination`, `hospitalization`, `boarding`: o valor granular já É
 *   idêntico ao valor de uma `ServiceCategory` existente — resolvido direto por
 *   `ServiceCategory::tryFrom()` em `ServiceCatalog::categoryFor()`, sem precisar de item
 *   granular equivalente.
 * - `behavioral` (`training.behavioral`, "Comportamental" dentro de Adestramento):
 *   colide, como STRING, com o case `ServiceCategory::BEHAVIORAL` (medicina
 *   comportamental — ato clínico do CRMV, sem relação com obediência/adestramento). Um
 *   valor só pode resolver para UMA categoria; `ServiceCatalog` prioriza o match direto
 *   de `ServiceCategory` (preserva cadastros legados que já usam `"behavioral"` com o
 *   sentido de medicina comportamental). Efeito colateral aceito: o item "Comportamental"
 *   do catálogo de Adestramento continua indisponível (mesmo estado de antes desta
 *   correção — não é regressão). Correção definitiva é renomear o valor do item no front
 *   (ex.: `obedience_behavioral`) — sinalizado para pet-business-specialist/
 *   frontend-specialist, fora do escopo desta correção de P0.
 * - `microchip` (`clinical.microchip`): mapeado para `ServiceCategory::OTHER` (decisão já
 *   registrada na Onda 3 — "sugerir novo case" ficou em aberto e não foi criado). Nenhum
 *   tipo tem `OTHER` em `service_categories` hoje, então o item continua sempre rejeitado
 *   — mesmo estado de antes. Se microchipagem virar oferta real, o caminho é dar a ela um
 *   `ServiceCategory` próprio (ou liberar `OTHER` para `vet`/`clinic`), não forçar aqui.
 */
enum ProfessionalServiceItem: string
{
    case DEWORMING = 'deworming';
    case MICROCHIP = 'microchip';
    case HEALTH_CERTIFICATE = 'health_certificate';
    case PRE_ANESTHETIC = 'pre_anesthetic';
    case NUTRITION_CONSULTATION = 'nutrition_consultation';
    case EMERGENCY_CARE = 'emergency_care';
    case BEHAVIORAL_CONSULTATION = 'behavioral_consultation';

    case CASTRATION = 'castration';
    case SURGERY_GENERAL = 'surgery_general';
    case EMERGENCY_SURGERY = 'emergency_surgery';
    case ORTHOPEDIC_SURGERY = 'orthopedic_surgery';
    case SOFT_TISSUE = 'soft_tissue';
    case DENTAL_SURGERY = 'dental_surgery';

    case XRAY = 'xray';
    case ULTRASOUND = 'ultrasound';
    case BLOOD_TEST = 'blood_test';
    case URINE_TEST = 'urine_test';
    case FECAL_TEST = 'fecal_test';
    case ECG = 'ecg';
    case ENDOSCOPY = 'endoscopy';
    case BIOPSY = 'biopsy';

    case ICU = 'icu';
    case PHYSIOTHERAPY = 'physiotherapy';
    case ACUPUNCTURE = 'acupuncture';
    case POST_SURGERY = 'post_surgery';

    case BATH = 'bath';
    case HAIRCUT = 'haircut';
    case HYGIENIC_GROOMING = 'hygienic_grooming';
    case NAIL_TRIM = 'nail_trim';
    case EAR_CLEANING = 'ear_cleaning';
    case TEETH_CLEANING = 'teeth_cleaning';
    case PET_SPA = 'pet_spa';

    case BASIC_TRAINING = 'basic_training';
    case SOCIALIZATION = 'socialization';
    case AGILITY = 'agility';
    case GUARD_TRAINING = 'guard_training';

    case DAYCARE = 'daycare';
    case BOARDING_LONG = 'boarding_long';
    case CAT_HOTEL = 'cat_hotel';
    case DOG_HOTEL = 'dog_hotel';

    /**
     * Resolvido via `lang/{locale}/registration.php` (`professional_service_item.*`) —
     * pt-BR é o default de `App::getLocale()`; só a rota do schema de cadastro troca o
     * locale por request (ver `App\Http\Middleware\SetLocaleFromAcceptLanguage`).
     */
    public function label(): string
    {
        return __('registration.professional_service_item.'.$this->value);
    }

    public function category(): ServiceCategory
    {
        return match ($this) {
            self::DEWORMING => ServiceCategory::VACCINATION,
            self::MICROCHIP => ServiceCategory::OTHER,
            self::HEALTH_CERTIFICATE, self::PRE_ANESTHETIC => ServiceCategory::CONSULTATION,
            self::NUTRITION_CONSULTATION => ServiceCategory::NUTRITION,
            self::EMERGENCY_CARE => ServiceCategory::EMERGENCY,
            self::BEHAVIORAL_CONSULTATION => ServiceCategory::BEHAVIORAL,
            self::CASTRATION, self::SURGERY_GENERAL, self::EMERGENCY_SURGERY,
            self::ORTHOPEDIC_SURGERY, self::SOFT_TISSUE, self::DENTAL_SURGERY => ServiceCategory::SURGERY,
            self::XRAY, self::ULTRASOUND, self::ECG, self::ENDOSCOPY, self::BIOPSY => ServiceCategory::IMAGING,
            self::BLOOD_TEST, self::URINE_TEST, self::FECAL_TEST => ServiceCategory::LABORATORY,
            self::ICU => ServiceCategory::HOSPITALIZATION,
            self::PHYSIOTHERAPY, self::ACUPUNCTURE, self::POST_SURGERY => ServiceCategory::REHABILITATION,
            self::BATH, self::HAIRCUT, self::HYGIENIC_GROOMING, self::NAIL_TRIM,
            self::EAR_CLEANING, self::TEETH_CLEANING, self::PET_SPA => ServiceCategory::GROOMING,
            self::BASIC_TRAINING, self::SOCIALIZATION, self::AGILITY, self::GUARD_TRAINING => ServiceCategory::TRAINING,
            self::DAYCARE, self::BOARDING_LONG, self::CAT_HOTEL, self::DOG_HOTEL => ServiceCategory::BOARDING,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
