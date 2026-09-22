<?php

namespace App\DataTransferObjects;

use App\Enums\InsightDimension;
use App\Enums\InsightGranularity;
use App\Enums\InsightMetric;
use App\Enums\SaleKind;
use Carbon\CarbonImmutable;

/**
 * Parâmetros já validados de `GET insights/{indicator}` (e das rotas irmãs `drill-down`/
 * `export`) — contrato docs/gap-simplesvet/specs/20-bi-produtividade-vendas-spec.md.
 *
 * Montado uma única vez por `App\Http\Requests\Insights\InsightQueryRequest::toInsightQuery()`
 * e reaproveitado por série, drill-down e export: é o que garante que os três enxergam
 * EXATAMENTE o mesmo recorte de dado (regra de negócio 2 — "drill-down é a prova do agregado").
 */
final readonly class InsightQuery
{
    /**
     * @param  list<int>  $employeeIds
     * @param  list<int>  $clientIds
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public InsightGranularity $granularity,
        public InsightMetric $metric,
        public InsightDimension $dimension,
        public SaleKind $kind = SaleKind::SALE,
        public array $employeeIds = [],
        public array $clientIds = [],
    ) {}

    /**
     * Mesma consulta, restrita a um subconjunto de colaboradores — usado por
     * `App\Services\Insights\ProductivityService` para forçar `me/productivity` a só
     * enxergar o próprio vínculo, sem duplicar os outros 6 campos.
     *
     * @param  list<int>  $employeeIds
     */
    public function withEmployeeIds(array $employeeIds): self
    {
        return new self(
            from: $this->from,
            to: $this->to,
            granularity: $this->granularity,
            metric: $this->metric,
            dimension: $this->dimension,
            kind: $this->kind,
            employeeIds: $employeeIds,
            clientIds: $this->clientIds,
        );
    }

    public function withDimension(InsightDimension $dimension): self
    {
        return new self(
            from: $this->from,
            to: $this->to,
            granularity: $this->granularity,
            metric: $this->metric,
            dimension: $dimension,
            kind: $this->kind,
            employeeIds: $this->employeeIds,
            clientIds: $this->clientIds,
        );
    }
}
