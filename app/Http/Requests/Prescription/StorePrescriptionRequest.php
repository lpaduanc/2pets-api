<?php

namespace App\Http\Requests\Prescription;

class StorePrescriptionRequest extends PrescriptionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'pet_id' => 'required|exists:pets,id',
            'prescription_date' => 'required|date',
            'valid_until' => 'nullable|date|after_or_equal:prescription_date',
            'items' => 'required|array|min:1|max:'.self::MAX_ITEMS,
        ], $this->itemRules(), $this->contentRules());
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function bodyParameters(): array
    {
        return [
            'pet_id' => ['description' => 'ID do pet que recebe a prescrição. Exige acesso de escrita ao pet.'],
            'prescription_date' => ['description' => 'Data da prescrição (YYYY-MM-DD).'],
            'valid_until' => ['description' => 'Data limite de validade (YYYY-MM-DD). Nunca anterior à data da prescrição.'],
            'items' => ['description' => 'Lista de medicamentos prescritos.'],
            'items.*.active_ingredient' => ['description' => 'Princípio ativo. Ao menos um de active_ingredient/commercial_name é obrigatório.'],
            'items.*.commercial_name' => ['description' => 'Nome comercial do medicamento.'],
            'items.*.route' => ['description' => 'Via de administração (slug do contrato §4).'],
            'items.*.pharmaceutical_form' => ['description' => 'Forma farmacêutica (slug do contrato §4).'],
            'items.*.dose_value' => ['description' => 'Dose final a administrar. Se omitida e dose_per_kg estiver presente, o backend tenta calcular a partir do peso do pet.'],
            'items.*.dose_per_kg' => ['description' => 'Dose por kg, usada para sugerir dose_value quando o peso do pet é conhecido.'],
            'items.*.frequency' => ['description' => 'Frequência (slug do contrato §4).'],
            'items.*.duration_text' => ['description' => 'Duração do tratamento em texto livre (ex.: "7 dias", "uso contínuo").'],
            'items.*.quantity_to_dispense' => ['description' => 'Quantidade total a dispensar (ex.: "1 caixa com 21 comprimidos").'],
            'is_controlled' => ['description' => 'Marca a receita como de medicamento controlado.'],
            'kind' => ['description' => 'Tipo de receituário: simple (padrão), special_control ou antimicrobial. Só simple tem fluxo operacional nesta fatia.'],
            'standalone_reason' => ['description' => 'Obrigatório quando não há medical_record_id: motivo da prescrição sem atendimento vinculado.'],
        ];
    }
}
