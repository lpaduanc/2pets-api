<?php

namespace App\Http\Requests\Organization;

use App\DataTransferObjects\Cnpj;
use App\Enums\Location\BrazilianState;
use App\Models\Organization;
use App\Rules\ValidCnpj;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * `PUT professional/organization` — dados cadastrais da empresa que viram o emitente da NF-e/NFC-e
 * (spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md §5.1). As travas de troca de
 * CNPJ (documento já emitido, certificado A1 de outra raiz) dependem do estado do banco e moram
 * em `OrganizationCnpjChangeGuard`, não aqui.
 *
 * Campos de endereço ausentes no corpo ficam como estão (só o que veio é gravado); `null` limpa.
 */
class UpdateOrganizationCompanyProfileRequest extends FormRequest
{
    private const ZIP_CODE_DIGITS = 8;

    private ?Organization $organization = null;

    /** Autoriza antes de validar: quem não é dono recebe 403, nunca o 422 do payload. */
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('manageCompanyProfile', $this->organization());
    }

    /** Organização principal do usuário, a mesma régua dos controllers fiscais. 404 sem ela. */
    public function organization(): Organization
    {
        if ($this->organization !== null) {
            return $this->organization;
        }

        $organizationId = app(CommercialScopeResolver::class)->primaryOrganizationId($this->user());
        abort_if($organizationId === null, 404, 'Profissional sem organização não tem dados de empresa para editar.');

        return $this->organization = Organization::findOrFail($organizationId);
    }

    /**
     * Documento e CEP trafegam e são gravados só com dígitos (regra de `DocumentNumber`); UF em
     * maiúsculas. Normalizar ANTES é o que faz `digits:N` e o `unique` compararem o formato do banco.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'cnpj' => $this->has('cnpj') ? Cnpj::stripMask($this->stringInput('cnpj')) : null,
            'zip_code' => $this->filled('zip_code') ? (string) preg_replace('/\D/', '', $this->stringInput('zip_code')) : null,
            'state' => $this->filled('state') ? strtoupper(trim($this->stringInput('state'))) : null,
        ], fn (?string $value): bool => $value !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            // Unicidade igual à constraint da coluna (`organizations.cnpj` UNIQUE, inclusive linhas
            // com soft delete) — sem `withoutTrashed`, senão o banco estouraria um 500.
            'cnpj' => [
                'bail', 'required', 'digits:'.Cnpj::DIGIT_COUNT, new ValidCnpj,
                Rule::unique('organizations', 'cnpj')->ignore($this->organization()->id),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:255'],
            'neighborhood' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::enum(BrazilianState::class)],
            'zip_code' => ['nullable', 'digits:'.self::ZIP_CODE_DIGITS],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'business_name.required' => 'Informe a razão social da empresa.',
            'cnpj.required' => 'Informe o CNPJ da empresa.',
            'cnpj.digits' => 'O CNPJ deve ter 14 dígitos.',
            'cnpj.unique' => 'Este CNPJ já está cadastrado em outra empresa do 2pets.',
            'state.enum' => 'Informe a sigla de uma UF válida (ex.: SP).',
            'zip_code.digits' => 'O CEP deve ter 8 dígitos.',
        ];
    }

    /** Campo que deveria ser texto mas veio como array/número não vira "Array" na normalização. */
    private function stringInput(string $key): string
    {
        $value = $this->input($key);

        return is_scalar($value) ? (string) $value : '';
    }
}
