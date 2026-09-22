<?php

namespace App\Services\Medical;

use App\Models\ExamRequest;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Pedido de exame como documento — contrato docs/gap-simplesvet/specs/16-modelos-exame-laudos-spec.md. */
final class ExamRequestService
{
    /**
     * @param  array{clinical_notes?: ?string, exam_type_ids?: list<int>}  $data
     */
    public function create(Pet $pet, User $requestedBy, array $data): ExamRequest
    {
        return DB::transaction(function () use ($pet, $requestedBy, $data): ExamRequest {
            $examRequest = ExamRequest::create([
                'pet_id' => $pet->id,
                'requested_by' => $requestedBy->id,
                'clinical_notes' => $data['clinical_notes'] ?? null,
            ]);

            $examRequest->examTypes()->sync($data['exam_type_ids'] ?? []);

            return $examRequest->fresh('examTypes');
        });
    }
}
