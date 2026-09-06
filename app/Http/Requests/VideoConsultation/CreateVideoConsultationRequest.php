<?php

namespace App\Http\Requests\VideoConsultation;

use App\Enums\TeleatendimentoType;
use App\Models\Appointment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateVideoConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'appointment_id' => 'nullable|integer|exists:appointments,id',
            'teleatendimento_type' => ['required', Rule::enum(TeleatendimentoType::class)],
            'previous_appointment_id' => 'nullable|integer|exists:appointments,id',
            'vet_counterpart_id' => 'nullable|integer|exists:users,id',
            'provider' => 'nullable|string|in:daily,twilio,jitsi',
            'recording_enabled' => 'nullable|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $type = TeleatendimentoType::tryFrom((string) $this->input('teleatendimento_type'));
            if (! $type) {
                return;
            }

            // Teleconsulta e telemonitoramento exigem consulta presencial prévia nos últimos 180 dias.
            if ($type->requiresPriorAppointment()) {
                $priorId = $this->input('previous_appointment_id');
                if (! $priorId) {
                    $v->errors()->add('previous_appointment_id', 'Teleconsulta e telemonitoramento exigem consulta presencial prévia.');

                    return;
                }

                $prior = Appointment::find($priorId);
                if (! $prior || $prior->client_id !== $this->user()->id) {
                    $v->errors()->add('previous_appointment_id', 'Consulta presencial prévia inválida.');

                    return;
                }

                if ($prior->status !== 'completed' || $prior->appointment_date < now()->subDays(180)) {
                    $v->errors()->add('previous_appointment_id', 'A consulta presencial prévia deve ter sido concluída nos últimos 180 dias.');
                }
            }

            // Teleinterconsulta é entre dois vets — exige vet_counterpart_id.
            if ($type->isBetweenVets() && ! $this->input('vet_counterpart_id')) {
                $v->errors()->add('vet_counterpart_id', 'Teleinterconsulta exige outro profissional (vet_counterpart_id).');
            }
        });
    }

    public function bodyParameters(): array
    {
        return [
            'teleatendimento_type' => ['description' => 'Tipo (CFMV 1.465/2023): teletriagem, teleconsulta, teleinterconsulta ou telemonitoramento.'],
            'previous_appointment_id' => ['description' => 'Obrigatório para teleconsulta e telemonitoramento — consulta presencial concluída nos últimos 180 dias.'],
            'vet_counterpart_id' => ['description' => 'Obrigatório para teleinterconsulta — outro médico-veterinário.'],
        ];
    }
}
