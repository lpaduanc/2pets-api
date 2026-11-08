<?php

namespace App\Services\Fiscal\Sefaz;

use App\DataTransferObjects\Fiscal\FiscalProviderResult;
use App\Enums\FiscalDocumentStatus;
use App\Exceptions\Fiscal\FiscalProviderUnavailable;
use App\Models\FiscalDocument;
use App\Models\FiscalNumberInvalidation;
use App\Services\Fiscal\FiscalDocumentFiles;
use NFePHP\NFe\Complements;
use NFePHP\NFe\Tools;

/**
 * Eventos e inutilização — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md §7.4.
 * Cancelamento = evento 110111 (chave + protocolo de autorização + justificativa de 15 a 255
 * caracteres; o prazo é da SEFAZ: 24 h na NF-e, 30 min na NFC-e na maioria das UFs — quem decide é
 * a resposta). Inutilização = faixa de números da série no ano. O XML protocolado
 * (procEventoNFe / procInutNFe) vai para o disco privado.
 */
final class SefazEvents
{
    public function __construct(
        private readonly SefazReplyReader $replies,
        private readonly FiscalDocumentFiles $files,
    ) {}

    public function cancel(Tools $tools, FiscalDocument $document, string $reason): FiscalProviderResult
    {
        $response = SefazCalls::guard(fn (): string => $tools->sefazCancela((string) $document->access_key, $reason, (string) $document->protocol));
        $reply = $this->accepted($this->replies->event($response));

        if ($reply->outcome === SefazOutcome::REJECTED) {
            return new FiscalProviderResult(FiscalDocumentStatus::REJECTED, rejectionReason: $reply->reason());
        }

        $eventXml = SefazCalls::guard(fn (): string => Complements::toAuthorize($tools->lastRequest, $response));
        $path = $this->files->put($document, (string) $document->access_key, FiscalDocumentFiles::CANCELLATION_XML, $eventXml);

        return new FiscalProviderResult(FiscalDocumentStatus::CANCELLED, protocol: $reply->protocol, xmlPath: $path);
    }

    public function invalidate(Tools $tools, FiscalNumberInvalidation $invalidation): FiscalProviderResult
    {
        $response = SefazCalls::guard(fn (): string => $tools->sefazInutiliza(
            (int) $invalidation->series,
            $invalidation->number_from,
            $invalidation->number_to,
            $invalidation->reason,
            SefazCodes::environment($invalidation->environment),
            substr((string) $invalidation->year, -2),
        ));
        $reply = $this->accepted($this->replies->invalidation($response));

        if ($reply->outcome === SefazOutcome::REJECTED) {
            return new FiscalProviderResult(FiscalDocumentStatus::REJECTED, rejectionReason: $reply->reason());
        }

        $procXml = SefazCalls::guard(fn (): string => Complements::toAuthorize($tools->lastRequest, $response));

        return new FiscalProviderResult(FiscalDocumentStatus::AUTHORIZED, providerId: $reply->protocol, protocol: $reply->protocol, xmlPath: $this->files->putInvalidation($invalidation, $procXml));
    }

    /** 108/109 (ou resposta vazia) = SEFAZ fora: falha de transporte, nunca recusa. */
    private function accepted(SefazReply $reply): SefazReply
    {
        if ($reply->outcome === SefazOutcome::UNAVAILABLE) {
            throw new FiscalProviderUnavailable('SEFAZ indisponível: '.$reply->reason());
        }

        return $reply;
    }
}
