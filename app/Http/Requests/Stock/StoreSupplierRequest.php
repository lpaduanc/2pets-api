<?php

namespace App\Http\Requests\Stock;

use App\Support\TaxpayerDocument;
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
        // CPF/CNPJ sem máscara e em maiúsculas: o CNPJ alfanumérico tem letra, que "só dígitos" apagaria.
        if ($this->filled('document')) {
            $this->merge(['document' => TaxpayerDocument::normalize((string) $this->input('document'))]);
        }

        if ($this->filled('address_zip')) {
            $this->merge(['address_zip' => preg_replace('/\D/', '', (string) $this->input('address_zip'))]);
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
            $prefix.'document' => ['nullable', 'string', 'regex:'.TaxpayerDocument::PATTERN],
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
        return ['document.regex' => 'Informe um CNPJ (14 caracteres, pode ter letras) ou um CPF (11 dígitos).'];
    }
}
