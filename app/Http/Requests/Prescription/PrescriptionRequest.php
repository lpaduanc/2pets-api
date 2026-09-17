<?php

namespace App\Http\Requests\Prescription;

use App\Enums\PrescriptionFrequency;
use App\Enums\PrescriptionKind;
use App\Enums\PrescriptionPharmaceuticalForm;
use App\Enums\PrescriptionRoute;
use App\Models\Prescription;
use App\Rules\ValidSupersededPrescription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Regras comuns a criar e editar prescrição.
 *
 * O shape de cada item é validado item a item (`items.*.campo`) porque a tela monta uma
 * linha por medicamento e mapeia o erro 422 pelo índice — `items.0.commercial_name` precisa
 * chegar como chave, que é exatamente o formato padrão do Laravel.
 *
 * Autorização NÃO mora aqui: quem decide se este profissional pode prescrever para este pet é
 * `AuthorizesPetAccess::resolvePetForWrite()` (gate de privacidade do pet, com PetVetAccess).
 * O `authorize()` só barra requisição sem usuário autenticado.
 */
abstract class PrescriptionRequest extends FormRequest
{
    /** Teto defensivo: nenhuma receita real tem tantos itens, e sem limite o payload é ilimitado. */
    protected const MAX_ITEMS = 50;

    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->assertEachItemHasAName($validator);
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function itemRules(): array
    {
        return [
            'items.*' => 'array',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.active_ingredient' => 'nullable|string|max:255',
            'items.*.commercial_name' => 'nullable|string|max:255',
            'items.*.concentration' => 'nullable|string|max:100',
            'items.*.pharmaceutical_form' => ['nullable', Rule::enum(PrescriptionPharmaceuticalForm::class)],
            'items.*.form_notes' => 'nullable|string|max:255',
            'items.*.route' => ['nullable', Rule::enum(PrescriptionRoute::class)],
            'items.*.route_notes' => 'nullable|string|max:255',
            'items.*.dose_value' => 'nullable|numeric|min:0|max:99999.999',
            'items.*.dose_unit' => 'nullable|string|max:20',
            'items.*.dose_per_kg' => 'nullable|numeric|min:0|max:99999.999',
            'items.*.frequency' => ['nullable', Rule::enum(PrescriptionFrequency::class)],
            'items.*.frequency_custom_hours' => 'nullable|integer|min:1|max:168',
            'items.*.frequency_notes' => 'nullable|string|max:255',
            'items.*.duration_text' => 'nullable|string|max:255',
            'items.*.is_continuous_use' => 'sometimes|boolean',
            'items.*.quantity_to_dispense' => 'nullable|string|max:255',
            'items.*.instructions_for_tutor' => 'nullable|string|max:1000',
            'items.*.is_controlled' => 'sometimes|boolean',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function contentRules(): array
    {
        return [
            'general_instructions' => 'nullable|string|max:1000',
            'warnings' => 'nullable|string|max:1000',
            'is_controlled' => 'sometimes|boolean',
            'kind' => ['sometimes', Rule::enum(PrescriptionKind::class)],
            'appointment_id' => 'nullable|exists:appointments,id',
            'medical_record_id' => 'nullable|exists:medical_records,id',
            // Obrigatório só quando a prescrição é standalone (sem atendimento vinculado) —
            // contrato §2. `required_without` conta `null` como ausente, então funciona tanto
            // quando o cliente omite a chave quanto quando manda `medical_record_id: null`.
            'standalone_reason' => 'required_without:medical_record_id|nullable|string|max:255',
            // Preenchido só quando esta prescrição é a REEMISSÃO que corrige uma cancelada
            // (contrato §1: "corrigir depois de emitida = cancelar e reemitir").
            // `ValidSupersededPrescription` garante que a apontada está CANCELADA, é do MESMO
            // pet e pertence ao MESMO profissional — sem isso, `supersedes_id` aceitaria
            // qualquer id existente, inclusive de outro pet ou outro profissional,
            // corrompendo a cadeia de correção.
            'supersedes_id' => [
                'nullable',
                'exists:prescriptions,id',
                new ValidSupersededPrescription($this->resolvePetIdForValidation(), $this->user()?->id),
            ],
        ];
    }

    /**
     * `pet_id` está no payload ao CRIAR, mas o `update()` nem aceita esse campo (imutável) —
     * nesse caso, resolve pelo registro já persistido.
     */
    private function resolvePetIdForValidation(): ?int
    {
        if ($this->filled('pet_id')) {
            return (int) $this->input('pet_id');
        }

        return Prescription::find($this->route('prescription'))?->pet_id;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'pet_id' => 'pet',
            'prescription_date' => 'data da prescrição',
            'valid_until' => 'validade',
            'items' => 'medicamentos',
            'general_instructions' => 'orientações gerais',
            'warnings' => 'advertências',
            'standalone_reason' => 'motivo da prescrição sem atendimento',
        ];
    }

    /**
     * Ao menos um de `active_ingredient`/`commercial_name` é obrigatório por item (contrato
     * §2) — receita sem nome nenhum de medicamento não é receita. Feito em `after()` (não em
     * `required_without` cruzado) porque a referência cruzada entre dois campos do MESMO
     * índice de um array wildcard não é resolvida de forma confiável pelas regras nativas.
     */
    private function assertEachItemHasAName(Validator $validator): void
    {
        foreach ((array) $this->input('items', []) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            if (filled($item['active_ingredient'] ?? null) || filled($item['commercial_name'] ?? null)) {
                continue;
            }

            $validator->errors()->add(
                "items.{$index}.commercial_name",
                'Informe o princípio ativo ou o nome comercial do medicamento.'
            );
        }
    }
}
