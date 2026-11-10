<?php

namespace App\Services\Fiscal\Nfse;

use DOMDocument;
use DOMXPath;

/**
 * Lê a NFS-e autorizada (e o evento) devolvida pela Sefin — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §6/§8. `infNFSe/@Id` = "NFS" + chave de 50 dígitos;
 * o evento tem `infEvento/@Id` = "EVT" + 59 dígitos. Também serve o DANFSe (só dados da NFS-e).
 */
final class NfseDocumentReader
{
    /**
     * Chave de 50 posições: cMun(7) + ambiente(1) + tipo de inscrição(1) + inscrição federal(14) +
     * nNFSe(13) + AAMM(4) + código(9) + DV(1). A inscrição pode ter letra (CNPJ alfanumérico) —
     * mesmo recorte do `TSIdNFSe` do leiaute RTC (`NFS[0-9]{9}[0-9A-Z]{14}[0-9]{27}`).
     */
    public const ACCESS_KEY_PATTERN = '/^[0-9]{9}[0-9A-Z]{14}[0-9]{27}$/';

    private DOMXPath $xpath;

    public function __construct(string $xml)
    {
        $document = new DOMDocument;
        $document->loadXML($xml, LIBXML_NONET);
        $this->xpath = new DOMXPath($document);
        $this->xpath->registerNamespace('n', NfseXmlWriter::NAMESPACE);
    }

    public function accessKey(): ?string
    {
        $key = substr($this->value('//n:infNFSe/@Id'), 3);

        return preg_match(self::ACCESS_KEY_PATTERN, $key) === 1 ? $key : null;
    }

    public function number(): ?int
    {
        $number = $this->value('//n:infNFSe/n:nNFSe');

        return $number === '' ? null : (int) $number;
    }

    public function eventId(): ?string
    {
        $id = $this->value('//n:infEvento/@Id');

        return $id === '' ? null : $id;
    }

    /** Texto de um caminho relativo ao `infNFSe` ("valores/vLiq", "DPS/infDPS/toma/xNome"). */
    public function get(string $path): string
    {
        $steps = implode('/', array_map(fn (string $step): string => 'n:'.$step, explode('/', $path)));

        return $this->value('//n:infNFSe/'.$steps);
    }

    private function value(string $query): string
    {
        $nodes = $this->xpath->query($query);

        return $nodes === false || $nodes->length === 0 ? '' : trim((string) $nodes->item(0)?->textContent);
    }
}
