<?php

namespace Tests\Support;

use App\Contracts\FiscalGatewayResolver;
use App\Contracts\FiscalProviderGateway;
use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\Enums\FiscalDocumentKind;
use App\Models\FiscalDocument;

/** Resolver de teste: todo dono e todo documento usam o MESMO driver (ex.: o roteirizado). */
final class FixedFiscalGatewayResolver implements FiscalGatewayResolver
{
    public function __construct(private readonly FiscalProviderGateway $gateway) {}

    public function forOwner(FiscalOwner $owner, FiscalDocumentKind $kind): FiscalProviderGateway
    {
        return $this->gateway;
    }

    public function forDocument(FiscalDocument $document): FiscalProviderGateway
    {
        return $this->gateway;
    }
}
