<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Pet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET pets/{pet}/invoices — contrato
 * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §4 "Leitura (tutor)". Só o
 * dono do pet lê, e só faturas não-rascunho — Invariante 2: rascunho nunca vaza para o tutor.
 * Fora do prefixo `professional/` de propósito, mesmo espírito de `PetTimelineController`.
 */
class PetInvoicesController extends Controller
{
    use PaginatesResults;

    private const DEFAULT_PER_PAGE = 20;

    public function __invoke(Request $request, int $pet): AnonymousResourceCollection
    {
        abort_unless(
            Pet::where('id', $pet)->where('user_id', $request->user()->id)->exists(),
            403,
            'Você não tem acesso às faturas deste pet.'
        );

        $invoices = Invoice::with(['professional', 'appointment', 'payments'])
            ->where('status', '!=', InvoiceStatus::DRAFT->value)
            ->where(fn ($query) => $query
                ->whereHas('medicalRecord', fn ($medicalRecord) => $medicalRecord->where('pet_id', $pet))
                ->orWhereHas('appointment', fn ($appointment) => $appointment->where('pet_id', $pet)))
            ->orderBy('issue_date', 'desc')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE));

        return InvoiceResource::collection($invoices);
    }
}
