<?php

namespace App\Enums;

enum ServiceCategory: string
{
    case CONSULTATION = 'consultation';
    case EMERGENCY = 'emergency';
    case SURGERY = 'surgery';
    case VACCINATION = 'vaccination';
    case GROOMING = 'grooming';
    case TRAINING = 'training';
    case BOARDING = 'boarding';
    case LABORATORY = 'laboratory';
    case IMAGING = 'imaging';
    case DENTAL = 'dental';
    case NUTRITION = 'nutrition';
    case BEHAVIORAL = 'behavioral';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CONSULTATION => 'Consulta',
            self::EMERGENCY => 'Emergência',
            self::SURGERY => 'Cirurgia',
            self::VACCINATION => 'Vacinação',
            self::GROOMING => 'Banho e Tosa',
            self::TRAINING => 'Adestramento',
            self::BOARDING => 'Hospedagem',
            self::LABORATORY => 'Exames Laboratoriais',
            self::IMAGING => 'Exames de Imagem',
            self::DENTAL => 'Odontologia',
            self::NUTRITION => 'Nutrição',
            self::BEHAVIORAL => 'Comportamental',
            self::OTHER => 'Outro',
        };
    }
}

