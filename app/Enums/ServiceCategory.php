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
    case HOSPITALIZATION = 'hospitalization';
    case REHABILITATION = 'rehabilitation';
    case OTHER = 'other';

    /**
     * Resolvido via `lang/{locale}/registration.php` (`service_category.*`) — pt-BR é o
     * default de `App::getLocale()`; só a rota do schema de cadastro troca o locale por
     * request (ver `App\Http\Middleware\SetLocaleFromAcceptLanguage`). Consumidores fora do
     * schema (ex.: mensagem de dupla trava em `ServiceEquipmentDependency`, busca pública)
     * continuam recebendo pt-BR de propósito — ver relato da tarefa de i18n do cadastro.
     */
    public function label(): string
    {
        return __('registration.service_category.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
