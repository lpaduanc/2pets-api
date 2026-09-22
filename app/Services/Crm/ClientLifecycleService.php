<?php

namespace App\Services\Crm;

use App\Enums\ClientLifecycleStage;
use Carbon\CarbonInterface;

/**
 * As 10 faixas do SimplesVet — contrato
 * `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`. Entrada: o "marco de retorno"
 * já calculado (regra de negócio 2 da spec — `GREATEST` de Appointment/Invoice/Sale), nunca
 * os três modelos diretamente; este serviço não sabe de onde a data veio.
 *
 * Convenção de fronteira (não especificada em nenhum lugar, fixada aqui): o limite pertence à
 * faixa mais RECENTE — "exatamente 1 mês atrás" ainda é `returned_recently`, "exatamente 3
 * meses atrás" ainda é `quiet_1_3m`, e assim por diante. `>=` em vez de `>` em cada corte.
 */
final class ClientLifecycleService
{
    public function classify(?CarbonInterface $lastInteractionAt): ClientLifecycleStage
    {
        if ($lastInteractionAt === null) {
            return ClientLifecycleStage::NO_PURCHASE_YET;
        }

        return $this->matchingBoundary($lastInteractionAt) ?? ClientLifecycleStage::CHURNED_5Y_PLUS;
    }

    private function matchingBoundary(CarbonInterface $lastInteractionAt): ?ClientLifecycleStage
    {
        $boundary = collect($this->boundaries())
            ->first(fn (array $candidate): bool => $lastInteractionAt->greaterThanOrEqualTo($candidate[0]));

        return $boundary[1] ?? null;
    }

    /**
     * Cortes calculados a partir de "agora" a cada chamada — nunca cacheados como
     * propriedade da classe, para o resultado não depender de QUANDO o serviço foi
     * instanciado dentro de um processo de longa duração (worker de fila).
     *
     * @return list<array{0: CarbonInterface, 1: ClientLifecycleStage}>
     */
    private function boundaries(): array
    {
        $now = now();

        return [
            [$now->copy()->subMonth(), ClientLifecycleStage::RETURNED_RECENTLY],
            [$now->copy()->subMonths(3), ClientLifecycleStage::QUIET_1_3M],
            [$now->copy()->subMonths(6), ClientLifecycleStage::QUIET_3_6M],
            [$now->copy()->subYear(), ClientLifecycleStage::QUIET_6_12M],
            [$now->copy()->subYears(2), ClientLifecycleStage::NEEDS_ATTENTION_1_2Y],
            [$now->copy()->subYears(3), ClientLifecycleStage::NEEDS_ATTENTION_2_3Y],
            [$now->copy()->subYears(5), ClientLifecycleStage::CHURNED_3_5Y],
        ];
    }
}
