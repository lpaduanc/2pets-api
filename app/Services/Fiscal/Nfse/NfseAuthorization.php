<?php

namespace App\Services\Fiscal\Nfse;

use App\DataTransferObjects\Fiscal\FiscalInvoicePayload;
use App\DataTransferObjects\Fiscal\FiscalProviderResult;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalEnvironment;
use App\Exceptions\Fiscal\FiscalProviderUnavailable;
use App\Models\FiscalDocument;
use App\Services\Fiscal\FiscalDocumentFiles;
use App\Services\Fiscal\Nfse\Danfse\DanfseRenderer;
use NFePHP\Common\Certificate;

/**
 * Emissão síncrona da NFS-e — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfse-nacional.md
 * §6. Write-ahead: a DPS assinada vai ao disco (e o Id dela a `provider_id`) ANTES do envio; a
 * retentativa reenvia a MESMA DPS. Se a primeira resposta se perdeu, a Sefin responde E0014 e a
 * NFS-e é recuperada pela consulta da DPS — nunca uma segunda nota para o mesmo serviço.
 */
final class NfseAuthorization
{
    private const DPS_ID_PREFIX = 'DPS';

    public function __construct(
        private readonly NfseDpsComposer $composer,
        private readonly NfseNacionalClient $client,
        private readonly NfseReplyReader $replies,
        private readonly FiscalDocumentFiles $files,
        private readonly DanfseRenderer $danfse,
    ) {}

    public function issue(FiscalDocument $document, FiscalInvoicePayload $payload, Certificate $certificate): FiscalProviderResult
    {
        $dps = $this->previousAttempt($document) ?? $this->writeAhead($document, $this->composer->compose($document, $payload, $certificate));
        $reply = $this->client->submitDps($certificate, self::environment($document), $dps['xml']);

        return match ($this->replies->authorization($reply)) {
            NfseReplyReader::ACCEPTED => $this->authorized($document, (string) $reply->nfseXml(), $dps['id'], $reply->alerts()),
            NfseReplyReader::DUPLICATE => $this->recover($document, $certificate, $dps['id']) ?? throw new FiscalProviderUnavailable('Sistema Nacional NFS-e: DPS já processada (E0014), mas a consulta ainda não devolveu a NFS-e.'),
            NfseReplyReader::REJECTED => new FiscalProviderResult(FiscalDocumentStatus::REJECTED, providerId: $dps['id'], rejectionReason: $this->replies->reason($reply)),
            default => throw new FiscalProviderUnavailable('Sistema Nacional NFS-e não processou a DPS: '.$this->replies->reason($reply)),
        };
    }

    /** NFS-e gerada a partir da DPS deste documento (consulta pelo Id da DPS); nula se não houver. */
    public function recover(FiscalDocument $document, Certificate $certificate, string $dpsId): ?FiscalProviderResult
    {
        $accessKey = $this->client->findAccessKey($certificate, self::environment($document), $dpsId);

        if ($accessKey === null) {
            return null;
        }

        $xml = $this->client->fetchNfse($certificate, self::environment($document), $accessKey)->nfseXml()
            ?? throw new FiscalProviderUnavailable('Sistema Nacional NFS-e: consulta da NFS-e sem o XML.');

        return $this->authorized($document, $xml, $dpsId, []);
    }

    /** @param  list<string>  $alerts */
    private function authorized(FiscalDocument $document, string $xml, string $dpsId, array $alerts): FiscalProviderResult
    {
        $nfse = new NfseDocumentReader($xml);
        $accessKey = $nfse->accessKey() ?? throw new FiscalProviderUnavailable('Sistema Nacional NFS-e: XML da NFS-e sem chave de acesso.');
        $xmlPath = $this->files->put($document, $accessKey, FiscalDocumentFiles::AUTHORIZED_NFSE, $xml);
        $pdf = $this->danfse->render($xml, $document->id);

        return new FiscalProviderResult(
            FiscalDocumentStatus::AUTHORIZED,
            accessKey: $accessKey,
            providerId: $dpsId,
            number: $nfse->number(),
            notice: $alerts === [] ? null : mb_substr(implode('; ', $alerts), 0, 480),
            xmlPath: $xmlPath,
            pdfPath: $pdf === null ? null : $this->files->put($document, $accessKey, FiscalDocumentFiles::DANFSE, $pdf),
        );
    }

    /** @return array{id: string, xml: string}|null DPS assinada de uma tentativa anterior */
    private function previousAttempt(FiscalDocument $document): ?array
    {
        $dpsId = (string) $document->provider_id;
        $isSignedDps = str_starts_with($dpsId, self::DPS_ID_PREFIX) && str_ends_with((string) $document->xml_path, FiscalDocumentFiles::SIGNED_DPS);
        $xml = $isSignedDps ? $this->files->read($document->xml_path) : null;

        return $xml === null ? null : ['id' => $dpsId, 'xml' => $xml];
    }

    /**
     * @param  array{id: string, xml: string}  $dps
     * @return array{id: string, xml: string}
     */
    private function writeAhead(FiscalDocument $document, array $dps): array
    {
        $path = $this->files->put($document, $dps['id'], FiscalDocumentFiles::SIGNED_DPS, $dps['xml']);
        $document->forceFill(['provider_id' => $dps['id'], 'xml_path' => $path])->save();

        return $dps;
    }

    private static function environment(FiscalDocument $document): FiscalEnvironment
    {
        return $document->environment ?? FiscalEnvironment::HOMOLOGATION;
    }
}
