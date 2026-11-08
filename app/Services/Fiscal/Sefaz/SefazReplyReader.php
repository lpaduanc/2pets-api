<?php

namespace App\Services\Fiscal\Sefaz;

use DOMDocument;
use DOMXPath;

/**
 * Lê as respostas da SEFAZ (envelope SOAP inteiro) e classifica o cStat — spec docs/gap-petflow/
 * specs/fase4-transmissao-propria-nfe-nfce.md §7.2.
 *
 * - Autorização/consulta: o status do LOTE (103 recebido, 104 processado, 105 em processamento)
 *   e, quando há `protNFe`, o do documento (100/150/120 autorizado, 110/205/301/302/303
 *   denegado, o resto rejeitado). 108/109 = SEFAZ fora: falha de transporte, não rejeição.
 * - Evento (cancelamento): 135/136/155 registrado.
 * - Inutilização: 102 homologada.
 */
final class SefazReplyReader
{
    private const AUTHORIZED = ['100', '150', '120'];

    private const DENIED = ['110', '205', '301', '302', '303'];

    /** 103 lote recebido (assíncrono), 105 lote em processamento. */
    private const PROCESSING = ['103', '105'];

    private const UNAVAILABLE = ['108', '109'];

    /** 204: a mesma NF-e já está na base (reenvio depois de timeout) — consultar pela chave. */
    private const DUPLICATE = ['204'];

    private const EVENT_REGISTERED = ['135', '136', '155'];

    private const INVALIDATION_HOMOLOGATED = '102';

    public function authorization(string $response): SefazReply
    {
        $xpath = $this->xpath($response);
        $batch = $this->value($xpath, '(//*[local-name()="cStat"])[1]');
        $batchReply = new SefazReply($this->classifyBatch($batch), $batch, $this->value($xpath, '(//*[local-name()="xMotivo"])[1]'), receipt: $this->nullable($xpath, '//*[local-name()="nRec"]'));

        if ($xpath->query('//*[local-name()="infProt"]')->length === 0) {
            return $batchReply;
        }

        $status = $this->value($xpath, '//*[local-name()="infProt"]/*[local-name()="cStat"]');

        return new SefazReply(
            $this->classifyDocument($status),
            $status,
            $this->value($xpath, '//*[local-name()="infProt"]/*[local-name()="xMotivo"]'),
            protocol: $this->nullable($xpath, '//*[local-name()="infProt"]/*[local-name()="nProt"]'),
        );
    }

    public function event(string $response): SefazReply
    {
        return $this->single($response, '//*[local-name()="infEvento"]', self::EVENT_REGISTERED);
    }

    public function invalidation(string $response): SefazReply
    {
        return $this->single($response, '//*[local-name()="retInutNFe"]/*[local-name()="infInut"]', [self::INVALIDATION_HOMOLOGATED]);
    }

    /** @param  list<string>  $success */
    private function single(string $response, string $group, array $success): SefazReply
    {
        $xpath = $this->xpath($response);
        $hasGroup = $xpath->query($group.'/*[local-name()="cStat"]')->length > 0;
        $prefix = $hasGroup ? $group : '(//*[local-name()="cStat"])[1]/..';
        $status = $this->value($xpath, $prefix.'/*[local-name()="cStat"]');

        return new SefazReply(
            match (true) {
                in_array($status, $success, true) => SefazOutcome::AUTHORIZED,
                $status === '' || in_array($status, self::UNAVAILABLE, true) => SefazOutcome::UNAVAILABLE,
                default => SefazOutcome::REJECTED,
            },
            $status,
            $this->value($xpath, $prefix.'/*[local-name()="xMotivo"]'),
            protocol: $this->nullable($xpath, $prefix.'/*[local-name()="nProt"]'),
        );
    }

    private function classifyBatch(string $status): SefazOutcome
    {
        return match (true) {
            in_array($status, self::PROCESSING, true) => SefazOutcome::PROCESSING,
            // Resposta vazia/ilegível também é transporte: tenta de novo, não rejeita.
            $status === '' || in_array($status, self::UNAVAILABLE, true) => SefazOutcome::UNAVAILABLE,
            in_array($status, self::DUPLICATE, true) => SefazOutcome::DUPLICATE,
            default => SefazOutcome::REJECTED,
        };
    }

    private function classifyDocument(string $status): SefazOutcome
    {
        return match (true) {
            in_array($status, self::AUTHORIZED, true) => SefazOutcome::AUTHORIZED,
            in_array($status, self::DENIED, true) => SefazOutcome::DENIED,
            in_array($status, self::DUPLICATE, true) => SefazOutcome::DUPLICATE,
            default => SefazOutcome::REJECTED,
        };
    }

    private function xpath(string $response): DOMXPath
    {
        $dom = new DOMDocument;
        // Resposta que não é XML (página de erro do balanceador, corpo vazio) não pode virar warning.
        $previous = libxml_use_internal_errors(true);
        $loaded = trim($response) !== '' && $dom->loadXML($response);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($loaded ? $dom : new DOMDocument);
    }

    private function value(DOMXPath $xpath, string $query): string
    {
        return (string) $this->nullable($xpath, $query);
    }

    private function nullable(DOMXPath $xpath, string $query): ?string
    {
        $node = @$xpath->query($query)?->item(0);

        return $node === null ? null : trim($node->textContent);
    }
}
