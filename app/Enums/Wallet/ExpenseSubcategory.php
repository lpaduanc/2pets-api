<?php

namespace App\Enums\Wallet;

/**
 * Subcategoria de um gasto do tutor (`tutor_expenses.subcategory`).
 *
 * O rótulo da subcategoria É a descrição padrão do gasto: quem toca em "Ração" não precisa
 * escrever "ração" de novo. Por isso os rótulos são curtos e no singular, do jeito que o
 * tutor fala. Cada subcategoria pertence a exatamente uma categoria (`category()`), e a
 * validação recusa a combinação errada (ex.: `vaccine` em `food`).
 */
enum ExpenseSubcategory: string
{
    // Alimentação
    case DRY_FOOD = 'dry_food';
    case WET_FOOD = 'wet_food';
    case TREATS = 'treats';
    case SUPPLEMENT = 'supplement';

    // Saúde
    case CONSULTATION = 'consultation';
    case VACCINE = 'vaccine';
    case DEWORMER = 'dewormer';
    case FLEA_TICK = 'flea_tick';
    case MEDICATION = 'medication';
    case EXAM = 'exam';
    case SURGERY = 'surgery';
    case EMERGENCY = 'emergency';

    // Banho e higiene
    case BATH = 'bath';
    case GROOMING = 'grooming';
    case BATH_AND_GROOMING = 'bath_and_grooming';
    case HYGIENE_PRODUCTS = 'hygiene_products';
    case CAT_LITTER = 'cat_litter';

    // Serviços
    case DAYCARE = 'daycare';
    case BOARDING = 'boarding';
    case DOG_WALKER = 'dog_walker';
    case PET_SITTER = 'pet_sitter';
    case TRAINING = 'training';
    case PET_TAXI = 'pet_taxi';

    // Acessórios
    case TOY = 'toy';
    case BED = 'bed';
    case COLLAR_LEASH = 'collar_leash';
    case CARRIER = 'carrier';
    case CLOTHES = 'clothes';
    case BOWL = 'bowl';

    // Plano e seguro
    case HEALTH_PLAN = 'health_plan';
    case PET_INSURANCE = 'pet_insurance';

    public function category(): ExpenseCategory
    {
        return match ($this) {
            self::DRY_FOOD, self::WET_FOOD, self::TREATS, self::SUPPLEMENT => ExpenseCategory::FOOD,
            self::CONSULTATION, self::VACCINE, self::DEWORMER, self::FLEA_TICK, self::MEDICATION,
            self::EXAM, self::SURGERY, self::EMERGENCY => ExpenseCategory::HEALTH,
            self::BATH, self::GROOMING, self::BATH_AND_GROOMING, self::HYGIENE_PRODUCTS,
            self::CAT_LITTER => ExpenseCategory::HYGIENE,
            self::DAYCARE, self::BOARDING, self::DOG_WALKER, self::PET_SITTER, self::TRAINING,
            self::PET_TAXI => ExpenseCategory::SERVICES,
            self::TOY, self::BED, self::COLLAR_LEASH, self::CARRIER, self::CLOTHES,
            self::BOWL => ExpenseCategory::ACCESSORIES,
            self::HEALTH_PLAN, self::PET_INSURANCE => ExpenseCategory::INSURANCE,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DRY_FOOD => 'Ração',
            self::WET_FOOD => 'Sachê e úmida',
            self::TREATS => 'Petisco',
            self::SUPPLEMENT => 'Suplemento',
            self::CONSULTATION => 'Consulta',
            self::VACCINE => 'Vacina',
            self::DEWORMER => 'Vermífugo',
            self::FLEA_TICK => 'Antipulgas e carrapatos',
            self::MEDICATION => 'Remédio',
            self::EXAM => 'Exame',
            self::SURGERY => 'Cirurgia',
            self::EMERGENCY => 'Emergência',
            self::BATH => 'Banho',
            self::GROOMING => 'Tosa',
            self::BATH_AND_GROOMING => 'Banho e tosa',
            self::HYGIENE_PRODUCTS => 'Produtos de higiene',
            self::CAT_LITTER => 'Areia e tapete higiênico',
            self::DAYCARE => 'Creche',
            self::BOARDING => 'Hotel',
            self::DOG_WALKER => 'Passeador',
            self::PET_SITTER => 'Pet sitter',
            self::TRAINING => 'Adestramento',
            self::PET_TAXI => 'Táxi pet',
            self::TOY => 'Brinquedo',
            self::BED => 'Cama',
            self::COLLAR_LEASH => 'Coleira e guia',
            self::CARRIER => 'Caixa de transporte',
            self::CLOTHES => 'Roupinha',
            self::BOWL => 'Comedouro e bebedouro',
            self::HEALTH_PLAN => 'Plano de saúde',
            self::PET_INSURANCE => 'Seguro',
        };
    }

    /**
     * Frequência que o app sugere ao marcar "repetir este gasto". Só existe para o que
     * costuma se repetir — sugerir mensalidade para cirurgia seria ruído.
     */
    public function suggestedFrequency(): ?ExpenseFrequency
    {
        return match ($this) {
            self::DRY_FOOD, self::WET_FOOD, self::CAT_LITTER, self::DAYCARE, self::DOG_WALKER,
            self::HEALTH_PLAN, self::PET_INSURANCE, self::FLEA_TICK => ExpenseFrequency::MONTHLY,
            self::BATH, self::BATH_AND_GROOMING => ExpenseFrequency::BIWEEKLY,
            self::DEWORMER => ExpenseFrequency::QUARTERLY,
            self::VACCINE => ExpenseFrequency::YEARLY,
            default => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
