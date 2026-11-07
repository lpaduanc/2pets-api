<?php

namespace App\Http\Requests\Appointment;

use App\DataTransferObjects\Cpf;
use App\Enums\PetSpecies;
use App\Enums\ServiceCategory;
use App\Rules\ScopedAppointmentTypeExists;
use App\Rules\ValidCpf;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST professional/appointments/new-patient` — contrato
 * `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §1.
 *
 * `existing_pet_id`/`create_new_pet` só fazem sentido no REENVIO depois de um 409 (§4) — o
 * primeiro pedido nunca os envia.
 */
class StoreNewPatientAppointmentRequest extends FormRequest
{
    /** @var list<string> */
    public const NON_VET_TYPES = ['grooming', 'training', 'boarding', 'other', 'nutrition', 'behavioral'];

    /**
     * Achado de segurança (auditoria 2026-09-16): a rota só tinha `auth:sanctum` — qualquer
     * usuário autenticado (inclusive um tutor comum) conseguia se auto-conceder `PetVetAccess`
     * nível `write` sobre o pet de outra pessoa via `NewPatientVetAccessGrantor`. Este endpoint
     * só faz sentido para quem exerce a profissão — mesma checagem que `PetVetAccessController::
     * grant()` já usa para o veterinário alvo de uma concessão manual.
     */
    public function authorize(): Response
    {
        // Veterinário, pet shop e banho e tosa (`User::canRegisterNewPatient`, a regra fica lá).
        return $this->user()?->canRegisterNewPatient()
            ? Response::allow()
            : Response::deny('Seu perfil não pode cadastrar paciente novo pelo agendamento.');
    }

    /**
     * CPF trafega e é comparado limpo (regra de projeto, ver `DocumentNumber`); os dois
     * booleanos de decisão do 409 chegam ausentes na maioria das vezes e `boolean()` no
     * controller/service já trataria `null` como falso — normalizar aqui evita repetir esse
     * cuidado em cada camada seguinte.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'tutor_cpf' => Cpf::stripMask($this->input('tutor_cpf')),
            'marketing_opt_in' => $this->boolean('marketing_opt_in'),
            'create_new_pet' => $this->boolean('create_new_pet'),
            // Tipo de atendimento padrão: consulta para o veterinário, banho e tosa para os demais.
            'type' => $this->input('type') ?? ($this->user()?->isVeterinarian() ? ServiceCategory::CONSULTATION->value : ServiceCategory::GROOMING->value),
        ]);
    }

    public function rules(CommercialScopeResolver $scope): array
    {
        return [
            'tutor_cpf' => ['bail', 'required', 'digits:'.Cpf::DIGIT_COUNT, app(ValidCpf::class)],
            'tutor_name' => ['required', 'string', 'max:255'],
            'tutor_email' => ['nullable', 'email', 'max:255'],
            'tutor_phone' => ['nullable', 'string', 'max:20'],
            'marketing_opt_in' => ['boolean'],

            'pet_name' => ['required', 'string', 'max:255'],
            'pet_species' => ['required', Rule::enum(PetSpecies::class)],

            'appointment_date' => ['required', 'date', 'after_or_equal:'.\App\Support\BusinessClock::todayString()],
            'appointment_time' => ['required', 'date_format:H:i'],
            'duration' => ['nullable', 'integer', 'min:15', 'max:480'],
            // Contrato docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md §3.
            // Quem não é veterinário só agenda atendimento não clínico (nenhum prontuário nasce):
            // banho e tosa, adestramento, hospedagem, outro, e nutrição/comportamental (que só são
            // ato clínico quando um veterinário executa, `ServiceCategory::isVeterinarianGated`).
            'type' => ['required', Rule::enum(ServiceCategory::class), Rule::when(! $this->user()?->isVeterinarian(), [Rule::in(self::NON_VET_TYPES)])],
            // Item 14 (achado do frontend) — ver comentário equivalente em `StoreAppointmentRequest`.
            'appointment_type_id' => ['nullable', 'integer', new ScopedAppointmentTypeExists($this->user(), $scope)],
            'reason' => ['nullable', 'string', 'max:1000'],

            'existing_pet_id' => ['nullable', 'integer', 'exists:pets,id'],
            'create_new_pet' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'tutor_cpf.required' => 'O CPF do tutor é obrigatório.',
            'tutor_cpf.digits' => 'O CPF deve conter 11 dígitos.',
            'tutor_name.required' => 'O nome do tutor é obrigatório.',
            'tutor_email.email' => 'Informe um e-mail válido.',
            'pet_name.required' => 'O nome do pet é obrigatório.',
            'pet_species.required' => 'A espécie do pet é obrigatória.',
            'appointment_date.required' => 'A data do agendamento é obrigatória.',
            'appointment_date.after_or_equal' => 'A data do agendamento deve ser hoje ou no futuro.',
            'appointment_time.required' => 'O horário é obrigatório.',
            'appointment_time.date_format' => 'O horário deve estar no formato HH:mm.',
            'type.required' => 'O tipo de atendimento é obrigatório.',
            'type.in' => 'Seu perfil só agenda atendimento não clínico (banho e tosa, adestramento, hospedagem, nutrição, comportamento ou outro).',
            'existing_pet_id.exists' => 'Pet não encontrado.',
        ];
    }
}
