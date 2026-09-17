<?php

namespace App\Rules;

use App\Models\Prescription;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `supersedes_id` só pode apontar para uma prescrição que:
 *   1. já está CANCELADA (contrato §1: "corrigir depois de emitida = cancelar e reemitir" —
 *      a reemissão não faz sentido antes do cancelamento);
 *   2. é do MESMO pet;
 *   3. pertence ao MESMO profissional autenticado.
 *
 * Sem isto, `supersedes_id` aceitava qualquer id existente em `prescriptions` — inclusive de
 * outro pet ou outro profissional — corrompendo a cadeia de correção justamente no módulo
 * onde a rastreabilidade importa mais.
 */
final class ValidSupersededPrescription implements ValidationRule
{
    public function __construct(
        private readonly ?int $petId,
        private readonly ?int $professionalId,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $superseded = Prescription::find($value);

        // `exists:prescriptions,id` (regra irmã, sempre presente junto desta) já cobre "id
        // não existe" — esta regra não duplica a mensagem para um id inválido.
        if ($superseded === null) {
            return;
        }

        if (! $superseded->isCanceled()) {
            $fail('Só é possível reemitir a partir de uma prescrição já cancelada.');

            return;
        }

        if ($superseded->pet_id !== $this->petId) {
            $fail('A prescrição reemitida precisa ser do mesmo pet.');

            return;
        }

        if ($superseded->professional_id !== $this->professionalId) {
            $fail('Você só pode reemitir uma prescrição cancelada por você mesmo.');
        }
    }
}
