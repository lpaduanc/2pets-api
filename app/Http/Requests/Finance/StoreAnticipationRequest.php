<?php

namespace App\Http\Requests\Finance;

/** Confirmação da antecipação (PLANO 2.1): os campos da simulação + a conta de destino. */
class StoreAnticipationRequest extends SimulateAnticipationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + ['account_id' => ['required', 'integer']];
    }
}
