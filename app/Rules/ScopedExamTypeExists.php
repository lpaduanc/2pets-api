<?php

namespace App\Rules;

use App\Models\ExamType;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `exam_type_ids.*` (POST exam-requests) só pode apontar para um `ExamType` global ou da
 * organização ativa do profissional chamador — mesmo escopo de `ExamType::scopeVisibleTo()`
 * usado por `ExamTypeController::show/update/destroy`. Um `exists:exam_types,id` puro
 * aceitaria (e vazaria) o catálogo de exame de OUTRA organização (revisão de segurança,
 * achado Médio 2 — mesma raiz do IDOR de `document_template_id`).
 */
final class ScopedExamTypeExists implements ValidationRule
{
    public function __construct(
        private readonly User $caller,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = ExamType::query()
            ->visibleTo($this->caller->activeOrganizationId())
            ->whereKey($value)
            ->exists();

        if (! $exists) {
            $fail('Tipo de exame não encontrado.');
        }
    }
}
