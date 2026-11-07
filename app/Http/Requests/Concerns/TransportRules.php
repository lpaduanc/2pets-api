<?php

namespace App\Http\Requests\Concerns;

use App\Enums\PetTransportType;
use Illuminate\Validation\Rule;

/**
 * `transport` no corpo do agendamento (PLANO 3.7): tipo e endereço com os mesmos campos do
 * endereço do cliente. Ausente = sem leva e traz.
 */
trait TransportRules
{
    /** @return array<string, mixed> */
    protected function transportRules(): array
    {
        return [
            'transport' => ['nullable', 'array'],
            'transport.type' => ['required_with:transport', Rule::enum(PetTransportType::class)],
            'transport.address' => ['required_with:transport', 'array'],
            'transport.address.zip_code' => ['nullable', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            // Sem transporte, nada é exigido (`required_without` puro disparava sem `transport`).
            'transport.address.address' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn (): bool => $this->filled('transport') && blank($this->input('transport.address.zip_code')))],
            'transport.address.complement' => ['nullable', 'string', 'max:255'],
            'transport.address.city' => ['nullable', 'string', 'max:100'],
            'transport.address.state' => ['nullable', 'string', 'size:2'],
        ];
    }
}
