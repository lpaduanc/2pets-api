<?php

namespace App\Services\Reports;

use App\Models\User;

/**
 * Registra toda exportação de contato em massa em `activity_log` (spatie/laravel-activitylog)
 * — contrato `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`, regra de negócio 4:
 * "não só usuário X exportou algo", precisa da QUANTIDADE de linhas e do FILTRO usado.
 */
final class BulkContactExportAuditor
{
    /** @param  array<string, mixed>  $filters */
    public function record(User $actor, string $panel, int $rowCount, array $filters): void
    {
        activity('bulk-contact-export')
            ->causedBy($actor)
            ->withProperties(['panel' => $panel, 'row_count' => $rowCount, 'filters' => $filters])
            ->log("Exportação de contatos em massa: {$panel} ({$rowCount} linhas)");
    }
}
