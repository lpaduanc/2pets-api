<?php

namespace Database\Seeders\Dataset;

use App\Enums\ServiceCategory;

/**
 * Faixa de preço (R$) e duração (minutos) plausíveis por categoria, para o mercado pet
 * brasileiro em 2026.
 *
 * Importa mais do que parece: `sort_by=price_low|price_high` e os filtros `min_price`/
 * `max_price` da busca ficam sem sentido se toda categoria custar o mesmo. Com faixas reais,
 * ordenar por preço passa a separar banho e tosa de cirurgia — que é a comparação que o
 * tutor faz.
 */
final class ServicePricing
{
    /**
     * @return array{0: int, 1: int}
     */
    public static function priceRangeFor(ServiceCategory $category): array
    {
        return match ($category) {
            ServiceCategory::CONSULTATION => [90, 260],
            ServiceCategory::EMERGENCY => [180, 480],
            ServiceCategory::SURGERY => [420, 3500],
            ServiceCategory::VACCINATION => [60, 190],
            ServiceCategory::GROOMING => [45, 190],
            ServiceCategory::TRAINING => [90, 380],
            ServiceCategory::BOARDING => [70, 230],
            ServiceCategory::LABORATORY => [45, 280],
            ServiceCategory::IMAGING => [120, 620],
            ServiceCategory::DENTAL => [250, 950],
            ServiceCategory::NUTRITION => [120, 300],
            ServiceCategory::BEHAVIORAL => [180, 420],
            ServiceCategory::HOSPITALIZATION => [180, 640],
            ServiceCategory::REHABILITATION => [100, 270],
            ServiceCategory::OTHER => [80, 200],
        };
    }

    public static function durationFor(ServiceCategory $category): int
    {
        return match ($category) {
            ServiceCategory::VACCINATION, ServiceCategory::LABORATORY => 20,
            ServiceCategory::CONSULTATION, ServiceCategory::OTHER => 30,
            ServiceCategory::IMAGING => 40,
            ServiceCategory::NUTRITION, ServiceCategory::EMERGENCY => 45,
            ServiceCategory::REHABILITATION => 50,
            ServiceCategory::BEHAVIORAL, ServiceCategory::TRAINING => 60,
            ServiceCategory::GROOMING, ServiceCategory::DENTAL => 90,
            ServiceCategory::SURGERY => 120,
            // Diária de hospedagem e de internação: 8 h e 12 h de ocupação da vaga, não de
            // atendimento — é o que a agenda precisa bloquear.
            ServiceCategory::BOARDING => 480,
            ServiceCategory::HOSPITALIZATION => 720,
        };
    }
}
