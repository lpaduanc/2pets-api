<?php

namespace App\Services\Medical;

use App\Models\ExamType;

/** Escrita do catálogo de exame — contrato docs/gap-simplesvet/specs/16-modelos-exame-laudos-spec.md. */
final class ExamTypeService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $organizationId): ExamType
    {
        return ExamType::create([
            ...$data,
            'organization_id' => $organizationId,
            'active' => $data['active'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ExamType $examType, array $data): ExamType
    {
        $examType->update($data);

        return $examType->fresh();
    }
}
