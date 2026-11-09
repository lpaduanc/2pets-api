<?php

namespace Tests\Feature\Fiscal\Concerns;

use App\Models\Service;
use Tests\Support\Fiscal\FakeNfseNacionalTransport;

/**
 * Clínica pronta para a NFS-e pelo Sistema Nacional (spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md): o cenário da fase A (emitente em São Paulo/SP, A1
 * de TESTE, disco falso), Simples Nacional com alíquota efetiva, `fiscal.nfse.enabled` ligado e a
 * API trocada pelo dublê — nada sai para a Sefin real.
 */
trait BuildsNfseScenario
{
    use BuildsSefazScenario;

    /** Medicina veterinária (Lista Nacional 05.01.01). */
    protected const VET_TAX_CODE = '050101';

    protected FakeNfseNacionalTransport $sefin;

    protected function buildNfseClinic(bool $withCertificate = true): void
    {
        $this->buildSefazClinic(withCertificate: $withCertificate, withCsc: false);
        config(['fiscal.nfse.enabled' => true]);
        $this->sefin = FakeNfseNacionalTransport::bind($this->app);
        $this->clinic->forceFill(['effective_sales_tax_rate' => '6.0000', 'municipal_registration' => '12345678'])->save();
    }

    /** @param  array<string, mixed>  $overrides */
    protected function makeTaxedService(array $overrides = []): Service
    {
        return $this->makeService($overrides + ['name' => 'Consulta veterinária', 'price' => 150.00, 'national_tax_code' => self::VET_TAX_CODE, 'municipal_service_code' => '001']);
    }

    /**
     * Venda de serviços paga em dinheiro.
     *
     * @param  list<array{0: Service, 1: int|string}>  $lines
     * @param  array{discount?: string, client_id?: int|null}  $options
     */
    protected function paidServiceSale(array $lines, array $options = []): int
    {
        $this->ensureRegisterOpen();
        $saleId = $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/sales', array_filter(['kind' => 'sale', 'fiscal_operation' => 'in_person_consumer', 'client_id' => $options['client_id'] ?? null]))
            ->assertCreated()->json('data.id');

        foreach ($lines as [$service, $quantity]) {
            $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'service', 'sellable_id' => $service->id, 'quantity' => $quantity])->assertCreated();
        }

        if (isset($options['discount'])) {
            $this->postJson("/api/professional/sales/{$saleId}/discount", ['discount_type' => 'amount', 'discount_value' => $options['discount']])->assertOk();
        }

        $total = $this->getJson("/api/professional/sales/{$saleId}")->assertOk()->json('data.total');
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($this->receptionist, 'cash'), 'amount' => $total])->assertCreated();

        return $saleId;
    }
}
