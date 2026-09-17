<?php

namespace App\Services\Medical;

use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\User;
use App\Support\PetReportedDataFieldRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Promove `medical_records.reported_pet_data` (o que o tutor informou NA consulta) para
 * `pets` — contrato docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md
 * §D. Ação EXCLUSIVA do tutor (`MedicalRecordPolicy::applyToPet`); o vet nunca escreve em
 * `pets` a partir do relato da consulta, só do próprio `PUT` de rascunho.
 *
 * O mapa `reported_pet_data` → colunas de `pets` mora em `PetReportedDataFieldRegistry` — este
 * Service só orquestra diff/apply e a transação, nunca a tabela de campos em si.
 */
final class PetDataPromotionService
{
    public function __construct(
        private readonly PetReportedDataFieldRegistry $fieldRegistry,
    ) {}

    /**
     * @return list<array{field: string, current_value: mixed, reported_value: mixed, differs: bool}>
     */
    public function diff(MedicalRecord $record): array
    {
        $pet = $record->pet;
        $reported = $record->reported_pet_data ?? [];
        $specs = $this->fieldRegistry->all();

        return collect($this->informedEntries($reported, $specs))
            ->map(fn (mixed $reportedValue, string $key): array => $this->diffEntry($key, $reportedValue, $pet, $specs))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>|null  $fields  subconjunto de chaves a aplicar; null = todas as
     *                                     presentes em `reported_pet_data`.
     * @return list<array{field: string, previous_value: mixed, new_value: mixed}>
     */
    public function apply(MedicalRecord $record, User $tutor, ?array $fields): array
    {
        $pet = $record->pet;
        $reported = $record->reported_pet_data ?? [];
        $specs = $this->fieldRegistry->all();
        $keys = $this->resolveApplicableKeys($reported, $specs, $fields);

        return DB::transaction(function () use ($pet, $record, $tutor, $reported, $specs, $keys): array {
            $before = $this->captureCurrentValues($keys, $specs, $pet);
            $this->applyAttributesToPet($pet, $reported, $specs, $keys);
            $this->markApplied($record, $tutor);

            return $this->buildChangeList($keys, $specs, $before, $pet);
        });
    }

    /**
     * @param  array<string, array{current: \Closure, toAttributes: \Closure}>  $specs
     */
    private function diffEntry(string $key, mixed $reportedValue, Pet $pet, array $specs): array
    {
        $currentValue = ($specs[$key]['current'])($pet);

        return [
            'field' => $key,
            'current_value' => $currentValue,
            'reported_value' => $reportedValue,
            'differs' => $this->valuesDiffer($currentValue, $reportedValue),
        ];
    }

    /**
     * @param  array<string, mixed>  $reported
     * @param  array<string, mixed>  $specs
     * @param  list<string>|null  $fields
     * @return list<string>
     */
    private function resolveApplicableKeys(array $reported, array $specs, ?array $fields): array
    {
        $reportedKeys = array_keys($this->informedEntries($reported, $specs));

        if ($fields === null) {
            return $reportedKeys;
        }

        return array_values(array_intersect($reportedKeys, $fields));
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $specs
     * @return array<string, mixed>
     */
    private function captureCurrentValues(array $keys, array $specs, Pet $pet): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = ($specs[$key]['current'])($pet);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $reported
     * @param  array<string, mixed>  $specs
     * @param  list<string>  $keys
     */
    private function applyAttributesToPet(Pet $pet, array $reported, array $specs, array $keys): void
    {
        $attributes = [];
        foreach ($keys as $key) {
            $attributes = [...$attributes, ...($specs[$key]['toAttributes'])($reported[$key])];
        }

        if ($attributes !== []) {
            $pet->update($attributes);
            $pet->refresh();
        }
    }

    /** Só quem promove o relato ao cadastro assina — o tutor (contrato §D). */
    private function markApplied(MedicalRecord $record, User $tutor): void
    {
        $record->forceFill([
            'reported_pet_data_applied_at' => now(),
            'reported_pet_data_applied_by' => $tutor->id,
        ])->save();
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $specs
     * @param  array<string, mixed>  $before
     * @return list<array{field: string, previous_value: mixed, new_value: mixed}>
     */
    private function buildChangeList(array $keys, array $specs, array $before, Pet $pet): array
    {
        $changes = [];
        foreach ($keys as $key) {
            $after = ($specs[$key]['current'])($pet);
            if ($this->valuesDiffer($before[$key], $after)) {
                $changes[] = ['field' => $key, 'previous_value' => $before[$key], 'new_value' => $after];
            }
        }

        return $changes;
    }

    /**
     * Só o que o tutor REALMENTE informou na consulta. Campo em branco não é um valor a
     * promover: promovê-lo escreveria `null` na coluna do pet e apagaria o cadastro do tutor
     * porque o vet não tocou naquele campo. Desde 2026-09-16 `ReportedPetDataNormalizer`
     * impede o vazio de ser gravado, mas registro antigo ainda pode tê-lo — e o dono do dado
     * aqui é o cadastro, não o relato.
     *
     * @param  array<string, mixed>  $reported
     * @param  array<string, mixed>  $specs
     * @return array<string, mixed>
     */
    private function informedEntries(array $reported, array $specs): array
    {
        return array_filter(
            array_intersect_key($reported, $specs),
            fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );
    }

    private function valuesDiffer(mixed $current, mixed $reported): bool
    {
        return $this->normalize($current) !== $this->normalize($reported);
    }

    /** Listas são comparadas por conteúdo, não por ordem — `['a','b']` e `['b','a']` são iguais. */
    private function normalize(mixed $value): mixed
    {
        if (is_array($value) && array_is_list($value)) {
            sort($value);
        }

        return $value;
    }
}
