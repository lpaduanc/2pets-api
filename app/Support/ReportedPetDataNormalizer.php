<?php

namespace App\Support;

/**
 * "Não informado" em `medical_records.reported_pet_data` é a AUSÊNCIA da chave, nunca uma
 * chave com valor vazio.
 *
 * Por que isto existe (bug de 2026-09-16): o formulário de consulta manda o bloco inteiro a
 * cada rascunho, com `''` nos campos que o vet não preencheu. O middleware global
 * `ConvertEmptyStringsToNull` transforma esses `''` em `null`, e `ValidReportedPetData` exige
 * valor tipado em toda chave presente — resultado, o rascunho PADRÃO (bloco em branco) voltava
 * 422 e nem o autosave nem o "Salvar" conseguiam gravar a consulta.
 *
 * Normalizar aqui, e não afrouxar a regra, é deliberado: o vazio não pode só PASSAR pela
 * validação, ele não pode ser GRAVADO. `PetDataPromotionService` decide o que promover ao
 * cadastro do pet por PRESENÇA de chave — um `food_brand: null` guardado viraria
 * `pets.food_brand = null` na hora em que o tutor aceitasse o relato, apagando o que ele mesmo
 * havia cadastrado, só porque o vet não tocou no campo.
 *
 * `false` e `0` NÃO são vazio: `physical_activity.daily_walk = false` é "não passeia", uma
 * resposta, não a falta dela.
 */
final class ReportedPetDataNormalizer
{
    /** Valor não-array volta intacto: quem reprova formato é `ValidReportedPetData`. */
    public function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $item = match ($key) {
                'physical_activity' => $this->normalizeMap($item),
                'continuous_medications' => $this->normalizeMedications($item),
                default => $item,
            };

            if (! $this->isBlank($item)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }

    /** Objeto de sub-chaves (`physical_activity`): some com as vazias, e com ele próprio se
     *  nada sobrar. */
    private function normalizeMap(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        return array_filter($value, fn (mixed $item): bool => ! $this->isBlank($item));
    }

    /**
     * Linha de medicamento sem nome é linha em branco que o vet abriu e não preencheu — o
     * `ValidReportedPetData` a reprovaria ("precisa de um nome") e derrubaria o rascunho
     * inteiro junto. `dosage`/`frequency` vazios saem da linha, não a invalidam.
     */
    private function normalizeMedications(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $medications = [];
        foreach ($value as $medication) {
            if (! is_array($medication)) {
                $medications[] = $medication;

                continue;
            }

            $filtered = array_filter($medication, fn (mixed $item): bool => ! $this->isBlank($item));
            if (! $this->isBlank($filtered['name'] ?? null)) {
                $medications[] = $filtered;
            }
        }

        return $medications;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
