<?php

namespace App\DataTransferObjects;

/**
 * Valor de um parâmetro de exame: guarda sempre o texto original — inclusive quando é um
 * valor legitimamente não numérico ("Negativo", "Reagente", "<0,1", "Ausente") — e, quando
 * o texto é parseável em pt-BR, também o número (`PtBrDecimal`). O numérico é o que permite
 * ordenar/comparar no gráfico de tendência (`ExamController::getHistory`) e derivar `status`
 * automaticamente; nunca substitui o texto exibido ao usuário.
 */
final readonly class ExamResultValue
{
    public ?float $numeric;

    public function __construct(public string $raw)
    {
        $this->numeric = PtBrDecimal::parse($raw);
    }
}
