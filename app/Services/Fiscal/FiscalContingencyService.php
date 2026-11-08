<?php

namespace App\Services\Fiscal;

use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\Enums\FiscalDocumentStatus;
use App\Models\FiscalDocument;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

/**
 * Contingência da NFC-e e reconciliação — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md
 * §4. Entrar: NFC-e passa a sair offline (tpEmis 9) e NF-e espera. Sair: transmite o que ficou.
 * `reconcile()` é o que `fiscal:reconcile` roda a cada 5 minutos para todos os donos.
 */
final class FiscalContingencyService
{
    public function __construct(private readonly FiscalDocumentDispatcher $dispatcher) {}

    /** @return array<string, mixed> */
    public function status(Organization $organization): array
    {
        $documents = (FiscalOwner::forOrganization($organization->id))->scope(FiscalDocument::query());

        return [
            'active' => $organization->isInFiscalContingency(),
            'started_at' => $organization->fiscal_contingency_started_at?->toIso8601String(),
            'reason' => $organization->fiscal_contingency_reason,
            'max_hours' => (int) config('fiscal.contingency.max_hours'),
            'documents_in_contingency' => (clone $documents)->where('status', FiscalDocumentStatus::CONTINGENCY->value)->count(),
            'overdue_documents' => (clone $documents)->where('status', FiscalDocumentStatus::CONTINGENCY->value)->where('created_at', '<', now()->subHours((int) config('fiscal.contingency.max_hours')))->count(),
            'pending_documents' => (clone $documents)->where('status', FiscalDocumentStatus::PENDING->value)->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function enter(Organization $organization, string $reason): array
    {
        abort_if($organization->isInFiscalContingency(), 422, 'A empresa já está em contingência.');

        $organization->forceFill(['fiscal_contingency_started_at' => now(), 'fiscal_contingency_reason' => trim($reason)])->save();

        return $this->status($organization->fresh());
    }

    /** @return array{status: array<string, mixed>, transmission: array<string, int>} */
    public function leave(Organization $organization): array
    {
        abort_unless($organization->isInFiscalContingency(), 422, 'A empresa não está em contingência.');

        $organization->forceFill(['fiscal_contingency_started_at' => null, 'fiscal_contingency_reason' => null])->save();
        $transmission = $this->transmit(FiscalOwner::forOrganization($organization->id));

        return ['status' => $this->status($organization->fresh()), 'transmission' => $transmission];
    }

    /**
     * Transmite a contingência e reenvia as pendentes do dono. Resultado por status final.
     *
     * @return array<string, int>
     */
    public function transmit(FiscalOwner $owner): array
    {
        return $this->syncAll($owner->scope(FiscalDocument::query())->whereIn('status', [FiscalDocumentStatus::CONTINGENCY->value, FiscalDocumentStatus::PENDING->value]));
    }

    /**
     * Todos os donos: consulta processando/contingência e reenvia pendente com retentativa vencida.
     * Pendente sem `next_retry_at` (anterior a esta fase) não é tocado.
     *
     * @return array<string, int>
     */
    public function reconcile(): array
    {
        return $this->syncAll(FiscalDocument::query()->where(fn (Builder $query) => $query
            ->whereIn('status', [FiscalDocumentStatus::PROCESSING->value, FiscalDocumentStatus::CONTINGENCY->value])
            ->orWhere(fn (Builder $pending) => $pending->where('status', FiscalDocumentStatus::PENDING->value)->where('next_retry_at', '<=', now()))));
    }

    /**
     * @param  Builder<FiscalDocument>  $query
     * @return array<string, int>
     */
    private function syncAll(Builder $query): array
    {
        $summary = [];

        $query->chunkById(100, function ($documents) use (&$summary): void {
            foreach ($documents as $document) {
                $status = $this->dispatcher->sync($document)->status->value;
                $summary[$status] = ($summary[$status] ?? 0) + 1;
            }
        });

        return $summary;
    }
}
