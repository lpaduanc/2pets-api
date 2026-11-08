<?php

namespace App\Http\Requests\Fiscal;

use Illuminate\Foundation\Http\FormRequest;

/** Entrada em contingência: a justificativa vai no `xJust` da NFC-e (15 a 255 caracteres). */
class EnterFiscalContingencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:15', 'max:255']];
    }
}
