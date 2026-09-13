<?php

namespace App\Enums;

/**
 * Setor de atuação da empresa parceira, qualificação comercial do Clube de Vantagens
 * (`CompleteProfileCompany.vue` — select `industry_sector`, único campo obrigatório do bloco).
 */
enum IndustrySector: string
{
    case TECHNOLOGY = 'technology';
    case HEALTHCARE = 'healthcare';
    case FINANCIAL = 'financial';
    case EDUCATION = 'education';
    case RETAIL = 'retail';
    case INDUSTRY = 'industry';
    case SERVICES = 'services';
    case GOVERNMENT = 'government';
    case NONPROFIT = 'nonprofit';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TECHNOLOGY => 'Tecnologia',
            self::HEALTHCARE => 'Saúde',
            self::FINANCIAL => 'Financeiro',
            self::EDUCATION => 'Educação',
            self::RETAIL => 'Varejo',
            self::INDUSTRY => 'Indústria',
            self::SERVICES => 'Serviços',
            self::GOVERNMENT => 'Governo',
            self::NONPROFIT => 'ONG/Terceiro Setor',
            self::OTHER => 'Outro',
        };
    }
}
