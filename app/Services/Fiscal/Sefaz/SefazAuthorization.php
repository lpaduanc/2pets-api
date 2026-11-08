<?php

namespace App\Services\Fiscal\Sefaz;

use App\DataTransferObjects\Fiscal\FiscalProviderResult;
use App\Enums\FiscalDocumentStatus;
use App\Exceptions\Fiscal\FiscalProviderUnavailable;
use App\Models\FiscalDocument;
use App\Services\Fiscal\FiscalDocumentFiles;
use NFePHP\NFe\Complements;
use NFePHP\NFe\Tools;

/**
 * Autorização de NF-e/NFC-e assinada — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md
 * §7. Envia SEMPRE síncrono (`indSinc=1`, um documento por lote): a UF que não processa síncrono
 * devolve 103 + recibo e o documento fica `processing` até a consulta do recibo. Autorizado (ou
 * denegado) vira `procNFe` no disco privado; autorizado ganha DANFE/DANFCE.
 *
 * Grava a chave e o XML assinado ANTES de enviar (write-ahead): se a resposta se perder no
 * caminho, a retentativa reenvia o MESMO XML e a SEFAZ responde 204 (duplicidade) em vez de uma
 * nota nova com o mesmo número — aí consultamos pela chave e recuperamos o protocolo.
 */
final class SefazAuthorization
{
    private const SYNCHRONOUS = 1;

    private const RECEIPT_LENGTH = 15;

    public function __construct(
        private readonly SefazReplyReader $replies,
        private readonly FiscalDocumentFiles $files,
        private readonly DanfeRenderer $danfe,
    ) {}

    public function submit(Tools $tools, FiscalDocument $document, string $accessKey, string $signedXml, bool $offline): FiscalProviderResult
    {
        $signedPath = $this->files->put($document, $accessKey, FiscalDocumentFiles::SIGNED_XML, $signedXml);
        $document->forceFill(['access_key' => $accessKey, 'xml_path' => $signedPath])->save();

        if ($offline) {
            // NFC-e offline (tpEmis 9): assinada e com QR Code, sai já com o DANFCE de
            // contingência; a transmissão vem depois (`checkStatus`, reconciliação).
            return new FiscalProviderResult(FiscalDocumentStatus::CONTINGENCY, accessKey: $accessKey, providerId: $accessKey, xmlPath: $signedPath, pdfPath: $this->pdf($document, $accessKey, $signedXml));
        }

        return $this->send($tools, $document, $accessKey, $signedXml);
    }

    /** Transmite a NFC-e emitida em contingência (o XML assinado guardado, sem remontar). */
    public function transmitStored(Tools $tools, FiscalDocument $document): FiscalProviderResult
    {
        return $this->send($tools, $document, (string) $document->access_key, $this->storedSigned($document));
    }

    /** Documento `processing`: consulta o recibo (quando houve) ou a chave. */
    public function query(Tools $tools, FiscalDocument $document): FiscalProviderResult
    {
        $receipt = $this->isReceipt($document->provider_id) ? (string) $document->provider_id : null;
        $response = SefazCalls::guard(fn (): string => $receipt !== null ? $tools->sefazConsultaRecibo($receipt) : $tools->sefazConsultaChave((string) $document->access_key));

        return $this->resolve($tools, $document, (string) $document->access_key, $this->storedSigned($document), $response, false);
    }

    /** XML assinado de uma tentativa anterior, se o modo de emissão (tpEmis na chave) ainda é o mesmo. */
    public function previousAttempt(FiscalDocument $document, bool $offline): ?string
    {
        $key = (string) $document->access_key;

        if (strlen($key) !== 44 || $key[34] !== ($offline ? '9' : '1')) {
            return null;
        }

        return $this->files->read($document->xml_path);
    }

    private function send(Tools $tools, FiscalDocument $document, string $accessKey, string $signedXml): FiscalProviderResult
    {
        $batchId = str_pad((string) $document->id, 15, '0', STR_PAD_LEFT);
        $response = SefazCalls::guard(fn (): string => $tools->sefazEnviaLote([$signedXml], $batchId, self::SYNCHRONOUS));

        return $this->resolve($tools, $document, $accessKey, $signedXml, $response, true);
    }

    private function resolve(Tools $tools, FiscalDocument $document, string $accessKey, string $signedXml, string $response, bool $mayLookUp): FiscalProviderResult
    {
        $reply = $this->replies->authorization($response);

        return match ($reply->outcome) {
            SefazOutcome::AUTHORIZED, SefazOutcome::DENIED => $this->protocolled($document, $accessKey, $signedXml, $response, $reply),
            SefazOutcome::PROCESSING => new FiscalProviderResult(FiscalDocumentStatus::PROCESSING, accessKey: $accessKey, providerId: $reply->receipt ?? $accessKey, notice: $reply->reason()),
            SefazOutcome::DUPLICATE => $mayLookUp
                ? $this->resolve($tools, $document, $accessKey, $signedXml, SefazCalls::guard(fn (): string => $tools->sefazConsultaChave($accessKey)), false)
                : new FiscalProviderResult(FiscalDocumentStatus::REJECTED, accessKey: $accessKey, rejectionReason: $reply->reason()),
            SefazOutcome::UNAVAILABLE => throw new FiscalProviderUnavailable('SEFAZ indisponível: '.$reply->reason()),
            SefazOutcome::REJECTED => new FiscalProviderResult(FiscalDocumentStatus::REJECTED, accessKey: $accessKey, rejectionReason: $reply->reason()),
        };
    }

    private function protocolled(FiscalDocument $document, string $accessKey, string $signedXml, string $response, SefazReply $reply): FiscalProviderResult
    {
        $authorized = $reply->outcome === SefazOutcome::AUTHORIZED;
        $procXml = SefazCalls::guard(fn (): string => Complements::toAuthorize($signedXml, $response));
        $xmlPath = $this->files->put($document, $accessKey, FiscalDocumentFiles::AUTHORIZED_XML, $procXml);

        return new FiscalProviderResult(
            $authorized ? FiscalDocumentStatus::AUTHORIZED : FiscalDocumentStatus::DENIED,
            accessKey: $accessKey,
            providerId: $accessKey,
            rejectionReason: $authorized ? null : $reply->reason(),
            protocol: $reply->protocol,
            // 120 = autorizado com alerta (NT 2026.002): autoriza e mostra o alerta.
            notice: $authorized && $reply->status !== '100' ? $reply->reason() : null,
            xmlPath: $xmlPath,
            pdfPath: $authorized ? $this->pdf($document, $accessKey, $procXml) : null,
        );
    }

    private function pdf(FiscalDocument $document, string $accessKey, string $xml): ?string
    {
        $pdf = $this->danfe->render($xml, $document->kind, $document->id);

        return $pdf === null ? null : $this->files->put($document, $accessKey, FiscalDocumentFiles::PDF, $pdf);
    }

    private function storedSigned(FiscalDocument $document): string
    {
        return $this->files->read($document->xml_path)
            ?? throw new FiscalProviderUnavailable('XML assinado do documento não encontrado no armazenamento.');
    }

    private function isReceipt(?string $providerId): bool
    {
        return $providerId !== null && strlen($providerId) === self::RECEIPT_LENGTH && ctype_digit($providerId);
    }
}
