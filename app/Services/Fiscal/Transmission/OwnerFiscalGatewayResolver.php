<?php

namespace App\Services\Fiscal\Transmission;

use App\Contracts\FiscalGatewayResolver;
use App\Contracts\FiscalProviderGateway;
use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\Enums\FiscalDocumentKind;
use App\Models\FiscalDocument;
use App\Services\Fiscal\LogFiscalProviderGateway;
use App\Services\Fiscal\Sefaz\SefazFiscalGateway;
use Illuminate\Contracts\Container\Container;

/**
 * Resolver padrão — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md §6. A regra
 * de "está pronto para a SEFAZ" é de `FiscalTransmissionReadiness` (a mesma que a tela mostra em
 * `transmission`), então tela e emissão nunca discordam.
 */
final class OwnerFiscalGatewayResolver implements FiscalGatewayResolver
{
    public function __construct(
        private readonly Container $container,
        private readonly FiscalTransmissionReadiness $readiness,
    ) {}

    public function forOwner(FiscalOwner $owner, FiscalDocumentKind $kind): FiscalProviderGateway
    {
        return $this->readiness->check($owner)->usesSefaz($kind) ? $this->sefaz() : $this->simulated();
    }

    public function forDocument(FiscalDocument $document): FiscalProviderGateway
    {
        return $document->provider === SefazFiscalGateway::NAME ? $this->sefaz() : $this->simulated();
    }

    private function sefaz(): FiscalProviderGateway
    {
        return $this->container->make(SefazFiscalGateway::class);
    }

    private function simulated(): FiscalProviderGateway
    {
        return $this->container->make(LogFiscalProviderGateway::class);
    }
}
