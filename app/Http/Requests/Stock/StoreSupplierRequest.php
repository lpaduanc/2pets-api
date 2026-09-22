<?php

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;

/** Fornecedor — docs/gap-simplesvet/06. Mesmo corpo no store e no update. */
class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['document', 'address_zip'] as $field) {
            if ($this->filled($field)) {
                $this->merge([$field => preg_replace('/\D/', '', (string) $this->input($field))]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::supplierRules();
    }

    /**
     * Reaproveitado pelo fornecedor criado a partir do XML (`StorePurchaseRequest`).
     *
     * @return array<string, mixed>
     */
    public static function supplierRules(string $prefix = ''): array
    {
        return [
            $prefix.'legal_name' => [$prefix === '' ? 'required' : 'required_with:supplier', 'string', 'max:255'],
            $prefix.'trade_name' => ['nullable', 'string', 'max:255'],
            $prefix.'document' => ['nullable', 'string', 'regex:/^(\d{11}|\d{14})$/'],
            $prefix.'state_registration' => ['nullable', 'string', 'max:20'],
            $prefix.'phone' => ['nullable', 'string', 'max:20'],
            $prefix.'email' => ['nullable', 'email', 'max:255'],
            $prefix.'sales_rep_name' => ['nullable', 'string', 'max:255'],
            $prefix.'sales_rep_phone' => ['nullable', 'string', 'max:20'],
            $prefix.'sales_rep_email' => ['nullable', 'email', 'max:255'],
            $prefix.'address_zip' => ['nullable', 'string', 'max:9'],
            $prefix.'address_street' => ['nullable', 'string', 'max:255'],
            $prefix.'address_number' => ['nullable', 'string', 'max:20'],
            $prefix.'address_complement' => ['nullable', 'string', 'max:255'],
            $prefix.'address_district' => ['nullable', 'string', 'max:255'],
            $prefix.'address_city' => ['nullable', 'string', 'max:255'],
            $prefix.'address_state' => ['nullable', 'string', 'size:2'],
            $prefix.'payment_terms' => ['nullable', 'string', 'max:100'],
            $prefix.'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            $prefix.'notes' => ['nullable', 'string', 'max:5000'],
            $prefix.'active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['document.regex' => 'CNPJ deve ter 14 dígitos (ou CPF, 11).'];
    }
}
