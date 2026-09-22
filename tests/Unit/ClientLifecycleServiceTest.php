<?php

namespace Tests\Unit;

use App\Enums\ClientLifecycleStage;
use App\Services\Crm\ClientLifecycleService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * As 10 faixas do SimplesVet — contrato
 * `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`. Puro cálculo (sem banco), mas
 * estende `Tests\TestCase` (não `PHPUnit\Framework\TestCase`) porque o serviço usa o helper
 * `now()`, que depende do container do Laravel estar de pé — mesmo cuidado que
 * `ServiceCategoryEncounterClassificationTest` já usa para o mesmo motivo.
 *
 * Convenção de fronteira fixada em `ClientLifecycleService`: o limite pertence à faixa mais
 * RECENTE (`>=`, não `>`).
 */
class ClientLifecycleServiceTest extends TestCase
{
    private ClientLifecycleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-21 12:00:00'));
        $this->service = new ClientLifecycleService;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_null_last_interaction_means_no_purchase_yet(): void
    {
        $this->assertSame(ClientLifecycleStage::NO_PURCHASE_YET, $this->service->classify(null));
    }

    #[DataProvider('boundaryCases')]
    public function test_classifies_boundary_dates_correctly(\Closure $lastInteractionAt, ClientLifecycleStage $expected): void
    {
        $this->assertSame($expected, $this->service->classify($lastInteractionAt()));
    }

    /** @return array<string, array{0: \Closure, 1: ClientLifecycleStage}> */
    public static function boundaryCases(): array
    {
        return [
            'ontem' => [fn () => now()->subDay(), ClientLifecycleStage::RETURNED_RECENTLY],
            'exatamente 1 mês atrás ainda é recente' => [fn () => now()->subMonth(), ClientLifecycleStage::RETURNED_RECENTLY],
            '1 mês e 1 segundo atrás já é quieto' => [fn () => now()->subMonth()->subSecond(), ClientLifecycleStage::QUIET_1_3M],
            'exatamente 3 meses atrás ainda é quiet_1_3m' => [fn () => now()->subMonths(3), ClientLifecycleStage::QUIET_1_3M],
            '3 meses e 1 segundo atrás já é quiet_3_6m' => [fn () => now()->subMonths(3)->subSecond(), ClientLifecycleStage::QUIET_3_6M],
            'exatamente 6 meses atrás ainda é quiet_3_6m' => [fn () => now()->subMonths(6), ClientLifecycleStage::QUIET_3_6M],
            '6 meses e 1 segundo atrás já é quiet_6_12m' => [fn () => now()->subMonths(6)->subSecond(), ClientLifecycleStage::QUIET_6_12M],
            'exatamente 1 ano atrás ainda é quiet_6_12m' => [fn () => now()->subYear(), ClientLifecycleStage::QUIET_6_12M],
            '1 ano e 1 segundo atrás já é needs_attention_1_2y' => [fn () => now()->subYear()->subSecond(), ClientLifecycleStage::NEEDS_ATTENTION_1_2Y],
            'exatamente 2 anos atrás ainda é needs_attention_1_2y' => [fn () => now()->subYears(2), ClientLifecycleStage::NEEDS_ATTENTION_1_2Y],
            '2 anos e 1 segundo atrás já é needs_attention_2_3y' => [fn () => now()->subYears(2)->subSecond(), ClientLifecycleStage::NEEDS_ATTENTION_2_3Y],
            'exatamente 3 anos atrás ainda é needs_attention_2_3y' => [fn () => now()->subYears(3), ClientLifecycleStage::NEEDS_ATTENTION_2_3Y],
            '3 anos e 1 segundo atrás já é churned_3_5y' => [fn () => now()->subYears(3)->subSecond(), ClientLifecycleStage::CHURNED_3_5Y],
            'exatamente 5 anos atrás ainda é churned_3_5y' => [fn () => now()->subYears(5), ClientLifecycleStage::CHURNED_3_5Y],
            '5 anos e 1 segundo atrás já é churned_5y_plus' => [fn () => now()->subYears(5)->subSecond(), ClientLifecycleStage::CHURNED_5Y_PLUS],
            '10 anos atrás é churned_5y_plus' => [fn () => now()->subYears(10), ClientLifecycleStage::CHURNED_5Y_PLUS],
        ];
    }
}
