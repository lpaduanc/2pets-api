<?php

namespace App\Services\Fiscal\Nfse;

use App\Enums\NfseLayout;
use App\Exceptions\Fiscal\NfeXmlInvalid;
use DOMDocument;

/**
 * Valida a DPS e o pedido de evento no XSD OFICIAL do leiaute do ambiente — spec
 * docs/gap-petflow/specs/fase4-transmissao-propria-nfse-nacional.md §1/§9 (pacotes em
 * `resources/fiscal/nfse/schemas`, sem alteração). Falha é rejeição local: não adianta enviar o
 * que a Sefin recusaria com E1235.
 */
final class NfseSchemaValidator
{
    public const DPS = 'DPS_v1.01.xsd';

    public const EVENT_REQUEST = 'pedRegEvento_v1.01.xsd';

    private const MAX_ERRORS = 5;

    /** @throws NfeXmlInvalid */
    public function validate(string $xml, NfseLayout $layout, string $schema): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;
            $valid = $document->loadXML($xml, LIBXML_NONET) && $document->schemaValidate($layout->schemaPath($schema), LIBXML_NONET);
            $errors = array_map(fn (\LibXMLError $error): string => trim($error->message), array_slice(libxml_get_errors(), 0, self::MAX_ERRORS));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $valid) {
            throw NfeXmlInvalid::because($errors === [] ? ['XML fora do esquema oficial da NFS-e.'] : $errors);
        }
    }
}
