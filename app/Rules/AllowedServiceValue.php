<?php

namespace App\Rules;

use App\Support\Registration\ProfessionalTypeCapabilities;
use App\Support\Registration\ServiceCatalog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Substitui `Rule::in($capabilities->allowedServiceCategoryValues())` — aceitava só o
 * valor de `ServiceCategory` e rejeitava os 90 itens granulares que o front realmente
 * envia em `services_offered` (P0 2026-09-13: vermifugação, raio-X, castração e afins
 * derrubavam o cadastro com 422). `ServiceCatalog::categoryFor()` resolve o valor
 * recebido (categoria OU item granular) antes de conferir contra o que o
 * `ProfessionalType` pode oferecer.
 */
final class AllowedServiceValue implements ValidationRule
{
    public function __construct(private readonly ProfessionalTypeCapabilities $capabilities) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $category = ServiceCatalog::categoryFor((string) $value);

        if ($category === null) {
            $fail(sprintf('O serviço "%s" não é reconhecido pela plataforma.', $value));

            return;
        }

        if (! $this->capabilities->allowsServiceCategory($category)) {
            $fail('Este serviço não está disponível para o tipo de cadastro selecionado.');
        }
    }
}
