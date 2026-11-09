<?php

namespace App\Services\Fiscal\Nfse;

use App\DataTransferObjects\Fiscal\FiscalInvoicePayload;
use App\Exceptions\Fiscal\NfeXmlInvalid;
use App\Models\FiscalDocument;
use NFePHP\Common\Certificate;

/**
 * DPS pronta para enviar: monta, assina e valida no XSD oficial do leiaute do ambiente — spec
 * docs/gap-petflow/specs/fase4-transmissao-propria-nfse-nacional.md §5/§6. Qualquer falha aqui é
 * rejeição local (`NfeXmlInvalid`), nunca indisponibilidade.
 */
final class NfseDpsComposer
{
    public function __construct(
        private readonly NfseDpsContextFactory $contexts,
        private readonly NfseDpsBuilder $builder,
        private readonly NfseXmlSigner $signer,
        private readonly NfseSchemaValidator $validator,
    ) {}

    /**
     * @return array{id: string, xml: string} Id da DPS e XML assinado
     *
     * @throws NfeXmlInvalid
     */
    public function compose(FiscalDocument $document, FiscalInvoicePayload $payload, Certificate $certificate): array
    {
        $context = $this->contexts->make($document, $payload);
        $signed = $this->signer->signDps($certificate, $this->builder->build($payload, $context));
        $this->validator->validate($signed, $context->layout, NfseSchemaValidator::DPS);

        return ['id' => $context->dpsId((string) $payload->emitter->document), 'xml' => $signed];
    }
}
