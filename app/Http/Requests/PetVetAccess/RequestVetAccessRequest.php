<?php

namespace App\Http\Requests\PetVetAccess;

use App\DataTransferObjects\Cpf;
use App\Enums\PetSpecies;
use App\Enums\VetAccessLevel;
use App\Models\Pet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestVetAccessRequest extends FormRequest
{
    /** Sexo biológico aceito no cadastro rápido feito pelo vet. */
    private const PET_GENDERS = ['male', 'female'];

    public function authorize(): bool
    {
        return $this->user()?->can('requestAccess', Pet::class) ?? false;
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Apenas veterinários podem solicitar acesso a pets.');
    }

    /**
     * O app envia o CPF com máscara (`123.456.789-00`). A coluna `users.cpf` guarda só
     * dígitos, então sem normalizar aqui a regra de tamanho reprovava todo CPF digitado
     * pelo usuário — era a causa do 422 no modo "cadastrar pet novo".
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('tutor_cpf')) {
            return;
        }

        $this->merge(['tutor_cpf' => Cpf::stripMask($this->input('tutor_cpf'))]);
    }

    public function rules(): array
    {
        return [
            // Exatamente um dos dois: pet_id (pet já existe) ou tutor_cpf (vet inicia por CPF).
            'pet_id' => ['required_without:tutor_cpf', 'nullable', 'integer', 'exists:pets,id'],
            'tutor_cpf' => ['required_without:pet_id', 'nullable', 'digits:'.Cpf::DIGIT_COUNT],
            'pet_data' => ['required_without:pet_id', 'nullable', 'array'],
            'pet_data.name' => ['required_with:pet_data', 'string', 'max:255'],
            'pet_data.species' => ['required_with:pet_data', Rule::enum(PetSpecies::class)],
            'pet_data.breed' => ['nullable', 'string', 'max:255'],
            'pet_data.birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'pet_data.gender' => ['required_with:pet_data', Rule::in(self::PET_GENDERS)],

            // Indicação de necessidade do vet. Quem concede o nível é o tutor, no aceite —
            // este valor nunca vira `access_level` sozinho.
            'requested_access_level' => ['nullable', Rule::enum(VetAccessLevel::class)],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'pet_id.exists' => 'Pet não encontrado.',
            'pet_id.required_without' => 'Informe o pet ou o CPF do tutor.',
            'tutor_cpf.required_without' => 'Informe o CPF do tutor ou selecione um pet existente.',
            'tutor_cpf.digits' => 'CPF deve conter 11 dígitos.',
            'pet_data.required_without' => 'Informe os dados do pet para cadastrá-lo em nome do tutor.',
            'pet_data.name.required_with' => 'O nome do pet é obrigatório.',
            'pet_data.species.required_with' => 'A espécie do pet é obrigatória.',
            'pet_data.species.enum' => 'Espécie inválida.',
            'pet_data.gender.required_with' => 'O sexo do pet é obrigatório.',
            'pet_data.gender.in' => 'Sexo inválido. Use macho ou fêmea.',
            'pet_data.birth_date.before_or_equal' => 'A data de nascimento não pode ser no futuro.',
            'requested_access_level.enum' => 'Nível de acesso inválido. Use: read, write ou full.',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'pet_id' => ['description' => 'ID do pet existente na plataforma. Obrigatório se tutor_cpf não for informado.'],
            'tutor_cpf' => ['description' => 'CPF do tutor, com ou sem máscara. Obrigatório se pet_id não for informado.'],
            'pet_data' => ['description' => 'Dados básicos do pet — usado quando o vet está criando o pet pelo CPF do tutor.'],
            'requested_access_level' => ['description' => 'Nível de acesso que o veterinário indica precisar: read, write ou full. Não vincula — o tutor decide o nível concedido no aceite. Default: read.'],
            'message' => ['description' => 'Mensagem opcional exibida ao tutor na hora de aprovar.'],
        ];
    }
}
