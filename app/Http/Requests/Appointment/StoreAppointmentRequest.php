<?php

namespace App\Http\Requests\Appointment;

use App\Enums\ServiceCategory;
use App\Models\Pet;
use App\Rules\ScopedAppointmentTypeExists;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Professional\ProfessionalClientsQuery;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contrato docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md §E: com a
 * consulta passando a valer acesso de escrita clínica (ver `ConsultationController::start`),
 * criar um `Appointment` para qualquer `pet_id`/`client_id` vira porta de escrita — não mais
 * só um agendamento inócuo. `withValidator` fecha as duas checagens que faltavam: o pet
 * precisa ser do próprio `client_id`, e o `client_id` precisa já ser cliente deste
 * profissional (mesma definição de `ProfessionalClientsQuery`).
 */
class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is handled by auth:sanctum middleware
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return; // pet_id/client_id já inválidos — checagem cruzada não se aplica.
            }

            $this->assertPetBelongsToClient($validator);
            $this->assertClientIsOwnClient($validator);
        });
    }

    private function assertPetBelongsToClient(Validator $validator): void
    {
        $pet = Pet::find($this->input('pet_id'));

        if ($pet !== null && (int) $pet->user_id !== (int) $this->input('client_id')) {
            $validator->errors()->add('pet_id', 'Este pet não pertence ao cliente informado.');
        }
    }

    private function assertClientIsOwnClient(Validator $validator): void
    {
        $isClient = app(ProfessionalClientsQuery::class)
            ->isClientOf((int) $this->user()->id, (int) $this->input('client_id'));

        if (! $isClient) {
            $validator->errors()->add(
                'client_id',
                'Este cliente ainda não pertence à sua carteira. Use "paciente novo" ou o agendamento normal.'
            );
        }
    }

    public function rules(CommercialScopeResolver $scope): array
    {
        return [
            'client_id' => ['required', 'exists:users,id'],
            'pet_id' => ['required', 'exists:pets,id'],
            'appointment_date' => ['required', 'date', 'after_or_equal:today'],
            'appointment_time' => ['required', 'date_format:H:i'],
            'duration' => ['nullable', 'integer', 'min:15', 'max:480'],
            // Contrato docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md
            // §3: `type` é o superconjunto de `ServiceCategory` — `checkup`/`exam` (valores
            // antigos, redundantes/imprecisos) não são mais aceitos em código novo.
            'type' => ['required', Rule::enum(ServiceCategory::class)],
            // Item 14 (achado do frontend): vínculo OPCIONAL ao cadastro configurável de
            // "tipo de atendimento" (cor/duração por dono) — nunca confundir com `type` acima.
            'appointment_type_id' => ['nullable', 'integer', new ScopedAppointmentTypeExists($this->user(), $scope)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // `service_id`/`price` únicos: contrato §13.2 os mantém como caminho LEGADO
            // (deprecado, não removido) — quando informado sem `price`, o controller
            // preenche a ESTIMATIVA pré-atendimento a partir de `Service.price`.
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('professional_id', $this->user()->id)],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            // Contrato §13.2/§13.7: um agendamento pode ter vários serviços ao mesmo
            // tempo (consulta + vacina + banho). Quando enviado, SUBSTITUI o caminho
            // legado acima como fonte do preço estimado.
            'services' => ['nullable', 'array', 'min:1'],
            'services.*.service_id' => [
                'required_with:services', 'integer',
                Rule::exists('services', 'id')->where('professional_id', $this->user()->id),
            ],
            'services.*.quantity' => ['nullable', 'numeric', 'min:0.01'],
            'services.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.required' => 'O cliente e obrigatorio.',
            'client_id.exists' => 'Cliente nao encontrado.',
            'pet_id.required' => 'O pet e obrigatorio.',
            'pet_id.exists' => 'Pet nao encontrado.',
            'appointment_date.required' => 'A data do agendamento e obrigatoria.',
            'appointment_date.after_or_equal' => 'A data do agendamento deve ser hoje ou no futuro.',
            'appointment_time.required' => 'O horario e obrigatorio.',
            'appointment_time.date_format' => 'O horario deve estar no formato HH:mm.',
            'type.required' => 'O tipo de consulta e obrigatorio.',
            'type.enum' => 'Tipo de consulta invalido.',
            'duration.min' => 'A duracao minima e de 15 minutos.',
            'price.min' => 'O preco deve ser um valor positivo.',
        ];
    }
}
