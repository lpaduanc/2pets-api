<?php

namespace App\Http\Requests\Hospitalization;

use App\Enums\HospitalizationCareStatus;
use App\Enums\HospitalizationCareType;
use App\Models\Hospitalization;
use App\Models\PrescriptionItem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contrato docs/atendimento-veterinario/12-modulo-clinico-internacao.md §4.
 *
 * `notes` obrigatório quando `status = not_done` (§4.3) — validado aqui, no backend, não só
 * na tela: o doc decidiu registrar a falha em vez de deixar silêncio ambíguo, e silêncio é o
 * que acontece se a validação ficar só no cliente.
 */
class StoreHospitalizationCareLogRequest extends FormRequest
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
        return [
            'care_type' => ['required', 'string', Rule::in(HospitalizationCareType::values())],
            'status' => ['required', 'string', Rule::in(HospitalizationCareStatus::values())],
            'performed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'prescription_item_id' => ['nullable', 'integer', 'exists:prescription_items,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->assertJustifiedWhenNotDone($validator);
            $this->assertPrescriptionItemBelongsToStay($validator);
        });
    }

    private function assertJustifiedWhenNotDone(Validator $validator): void
    {
        if ($this->input('status') !== HospitalizationCareStatus::NOT_DONE->value) {
            return;
        }

        if (! $this->filled('notes')) {
            $validator->errors()->add('notes', 'Informe o motivo quando o cuidado não foi realizado.');
        }
    }

    /**
     * O item precisa pertencer a uma prescrição vinculada ao MESMO agendamento da internação
     * — fecha o laço entre o que foi prescrito e o que foi de fato administrado.
     */
    private function assertPrescriptionItemBelongsToStay(Validator $validator): void
    {
        if (! $this->filled('prescription_item_id')) {
            return;
        }

        $hospitalization = Hospitalization::find($this->route('id'));

        $belongs = $hospitalization !== null && PrescriptionItem::query()
            ->whereKey($this->input('prescription_item_id'))
            ->whereHas('prescription', fn ($query) => $query->where('appointment_id', $hospitalization->appointment_id))
            ->exists();

        if (! $belongs) {
            $validator->errors()->add('prescription_item_id', 'Este item de prescrição não pertence a esta internação.');
        }
    }

    public function bodyParameters(): array
    {
        return [
            'care_type' => ['description' => 'Categoria do cuidado (ex.: feeding, medication_administration, hygiene, mobility, vital_monitoring, elimination, wound_care, other).'],
            'status' => ['description' => 'done, not_done ou not_applicable.'],
            'performed_at' => ['description' => 'Momento do cuidado (ou do horário previsto, quando not_done). Default: agora.'],
            'notes' => ['description' => 'Obrigatório quando status = not_done — motivo do não-cumprimento.'],
            'prescription_item_id' => ['description' => 'Item de prescrição administrado, quando care_type = medication_administration.'],
        ];
    }
}
