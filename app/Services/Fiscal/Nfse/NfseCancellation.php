<?php

namespace App\Services\Fiscal\Nfse;

use App\DataTransferObjects\Cnpj;
use App\DataTransferObjects\Fiscal\FiscalProviderResult;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalEnvironment;
use App\Enums\NfseLayout;
use App\Exceptions\Fiscal\FiscalProviderUnavailable;
use App\Models\FiscalDocument;
use App\Models\Organization;
use App\Services\Fiscal\FiscalDocumentFiles;
use Carbon\CarbonImmutable;
use NFePHP\Common\Certificate;
use NFePHP\Common\TimeZoneByUF;

/**
 * Cancelamento da NFS-e (evento e101101) — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §7 / Anexo II v1.01. `infPedReg/@Id` = "PRE" + chave
 * (50) + código do evento (6). Motivo 9 (Outros) com a justificativa digitada (15–255); o prazo é
 * parametrizado pelo município (recusa E0822 volta como `REJECTED`).
 */
final class NfseCancellation
{
    private const EVENT_CODE = '101101';

    private const EVENT_DESCRIPTION = 'Cancelamento de NFS-e';

    private const REASON_OTHER = '9';

    private const ENVIRONMENT_CODES = [FiscalEnvironment::PRODUCTION->value => '1', FiscalEnvironment::HOMOLOGATION->value => '2'];

    public function __construct(
        private readonly NfseXmlSigner $signer,
        private readonly NfseSchemaValidator $validator,
        private readonly NfseNacionalClient $client,
        private readonly NfseReplyReader $replies,
        private readonly FiscalDocumentFiles $files,
    ) {}

    public function cancel(FiscalDocument $document, string $reason, Certificate $certificate): FiscalProviderResult
    {
        $environment = $document->environment ?? FiscalEnvironment::HOMOLOGATION;
        $request = $this->signer->signEventRequest($certificate, $this->request($document, $reason, $environment));
        $this->validator->validate($request, NfseLayout::forEnvironment($environment), NfseSchemaValidator::EVENT_REQUEST);
        $reply = $this->client->registerEvent($certificate, $environment, (string) $document->access_key, $request);

        return match ($this->replies->event($reply)) {
            NfseReplyReader::ACCEPTED => $this->registered($document, $reply->eventXml() ?? $request),
            NfseReplyReader::UNAVAILABLE => throw new FiscalProviderUnavailable('Sistema Nacional NFS-e não processou o cancelamento: '.$this->replies->reason($reply)),
            default => new FiscalProviderResult(FiscalDocumentStatus::REJECTED, rejectionReason: $this->replies->reason($reply)),
        };
    }

    private function registered(FiscalDocument $document, string $eventXml): FiscalProviderResult
    {
        $path = $this->files->put($document, (string) $document->access_key, FiscalDocumentFiles::NFSE_CANCELLATION_XML, $eventXml);

        return new FiscalProviderResult(FiscalDocumentStatus::CANCELLED, protocol: (new NfseDocumentReader($eventXml))->eventId(), xmlPath: $path);
    }

    private function request(FiscalDocument $document, string $reason, FiscalEnvironment $environment): string
    {
        $organization = Organization::findOrFail($document->organization_id);
        $writer = new NfseXmlWriter;
        $root = $writer->root('pedRegEvento', ['versao' => NfseLayout::VERSION]);
        $info = $writer->group($root, 'infPedReg', ['Id' => 'PRE'.$document->access_key.self::EVENT_CODE]);
        $writer->add($info, 'tpAmb', self::ENVIRONMENT_CODES[$environment->value]);
        $writer->add($info, 'verAplic', NfseXmlWriter::text((string) config('fiscal.nfse.app_version'), 20));
        $writer->add($info, 'dhEvento', CarbonImmutable::now(TimeZoneByUF::get((string) $organization->state))->subSeconds((int) config('fiscal.nfse.clock_skew_seconds'))->format('Y-m-d\TH:i:sP'));
        $writer->add($info, 'CNPJAutor', Cnpj::stripMask((string) $organization->cnpj));
        $writer->add($info, 'chNFSe', (string) $document->access_key);
        $event = $writer->group($info, 'e'.self::EVENT_CODE);
        $writer->add($event, 'xDesc', self::EVENT_DESCRIPTION);
        $writer->add($event, 'cMotivo', self::REASON_OTHER);
        $writer->add($event, 'xMotivo', NfseXmlWriter::text($reason, 255));

        return $writer->xml();
    }
}
