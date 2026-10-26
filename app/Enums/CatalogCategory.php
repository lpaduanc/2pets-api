<?php

namespace App\Enums;

/**
 * `catalog_products.category` — tipo de produto do catálogo global. A espécie mora em coluna
 * própria (`species`), então "ração seca" vale para cão e gato.
 *
 * A subcategoria fina (ex.: "Peitorais", "Areia Higiênica") mora em `attributes.subcategory`;
 * aqui fica só o agrupamento que o PDV e o BI usam.
 */
enum CatalogCategory: string
{
    case DRY_FOOD = 'dry_food';
    case WET_FOOD = 'wet_food';
    case THERAPEUTIC_FOOD = 'therapeutic_food';
    case NATURAL_FOOD = 'natural_food';
    case TREATS = 'treats';
    case COLLARS_LEASHES = 'collars_leashes';
    case TOYS = 'toys';
    case CLOTHING = 'clothing';
    case BEDS = 'beds';
    case FEEDING_ACCESSORIES = 'feeding_accessories';
    case TRANSPORT = 'transport';
    case HYGIENE = 'hygiene';
    case TRAINING_PROTECTION = 'training_protection';
    case PHARMACY = 'pharmacy';

    public function label(): string
    {
        return match ($this) {
            self::DRY_FOOD => 'Ração seca',
            self::WET_FOOD => 'Ração úmida',
            self::THERAPEUTIC_FOOD => 'Ração terapêutica',
            self::NATURAL_FOOD => 'Alimento natural',
            self::TREATS => 'Petiscos',
            self::COLLARS_LEASHES => 'Coleiras, guias e peitorais',
            self::TOYS => 'Brinquedos e arranhadores',
            self::CLOTHING => 'Roupas e acessórios de moda',
            self::BEDS => 'Camas e casinhas',
            self::FEEDING_ACCESSORIES => 'Comedouros e bebedouros',
            self::TRANSPORT => 'Transporte',
            self::HYGIENE => 'Higiene e limpeza',
            self::TRAINING_PROTECTION => 'Proteção e adestramento',
            self::PHARMACY => 'Medicamentos',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
