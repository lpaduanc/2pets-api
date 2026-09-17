<?php

namespace App\Http\Resources\Professional;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Projeção mínima do tutor na resposta de `POST professional/appointments/new-patient` — só os
 * campos que o próprio profissional acabou de digitar no formulário, nunca o `UserResource`
 * completo (endereço, data de nascimento — ver `fluxo-acesso-vet-pet.md` na memória do agente
 * para o precedente de minimização de PII neste tipo de endpoint).
 */
class NewPatientTutorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'cpf' => $this->cpf,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_unclaimed' => $this->isUnclaimed(),
        ];
    }
}
