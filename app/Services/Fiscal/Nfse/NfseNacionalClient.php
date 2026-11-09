<?php

namespace App\Services\Fiscal\Nfse;

use App\Enums\FiscalEnvironment;
use App\Exceptions\Fiscal\FiscalProviderUnavailable;
use App\Services\Fiscal\Nfse\Transport\NfseHttpExchange;
use App\Services\Fiscal\Nfse\Transport\NfseNacionalTransport;
use NFePHP\Common\Certificate;

/**
 * Chamadas à API da Sefin Nacional — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §6/§7 (métodos do Manual dos Contribuintes,
 * Emissor Público Nacional): `POST /nfse`, `GET /nfse/{chave}`, `GET /dps/{id}`,
 * `POST /nfse/{chave}/eventos`. Documentos vão em XML compactado (GZip + Base64) dentro de JSON.
 *
 * Fronteira de TRANSPORTE: exceção de rede/TLS, 5xx, 408, 429 e resposta que não é JSON viram
 * `FiscalProviderUnavailable` (o documento fica pendente e a retentativa assume).
 */
final class NfseNacionalClient
{
    private const MESSAGE_MAX_LENGTH = 300;

    private const RETRYABLE_STATUSES = [408, 425, 429];

    private const NOT_FOUND = 404;

    public function __construct(private readonly NfseNacionalTransport $transport) {}

    public function submitDps(Certificate $certificate, FiscalEnvironment $environment, string $signedXml): NfseReply
    {
        return $this->call($certificate, new NfseHttpExchange(NfseHttpExchange::POST, self::url($environment, 'nfse'), ['dpsXmlGZipB64' => self::pack($signedXml)]));
    }

    public function fetchNfse(Certificate $certificate, FiscalEnvironment $environment, string $accessKey): NfseReply
    {
        return $this->call($certificate, new NfseHttpExchange(NfseHttpExchange::GET, self::url($environment, 'nfse/'.$accessKey)));
    }

    /** Chave da NFS-e gerada a partir desta DPS; nula se nenhuma foi gerada (404). */
    public function findAccessKey(Certificate $certificate, FiscalEnvironment $environment, string $dpsId): ?string
    {
        return $this->call($certificate, new NfseHttpExchange(NfseHttpExchange::GET, self::url($environment, 'dps/'.$dpsId)), allowNotFound: true)->accessKey();
    }

    public function registerEvent(Certificate $certificate, FiscalEnvironment $environment, string $accessKey, string $signedXml): NfseReply
    {
        $url = self::url($environment, sprintf('nfse/%s/eventos', $accessKey));

        return $this->call($certificate, new NfseHttpExchange(NfseHttpExchange::POST, $url, ['pedidoRegistroEventoXmlGZipB64' => self::pack($signedXml)]));
    }

    private function call(Certificate $certificate, NfseHttpExchange $request, bool $allowNotFound = false): NfseReply
    {
        try {
            $answer = $this->transport->send($request, $certificate);
        } catch (FiscalProviderUnavailable $unavailable) {
            throw $unavailable;
        } catch (\Throwable $failure) {
            throw new FiscalProviderUnavailable('Sistema Nacional NFS-e: '.mb_substr($failure->getMessage(), 0, self::MESSAGE_MAX_LENGTH), previous: $failure);
        }

        return self::reply($answer, $allowNotFound);
    }

    private static function reply(NfseHttpExchange $answer, bool $allowNotFound): NfseReply
    {
        $status = (int) $answer->status;
        $body = $answer->decoded();

        if ($allowNotFound && $status === self::NOT_FOUND) {
            return new NfseReply($status, [], false);
        }

        if ($status >= 500 || in_array($status, self::RETRYABLE_STATUSES, true) || $body === null) {
            throw new FiscalProviderUnavailable(sprintf('Sistema Nacional NFS-e indisponível (HTTP %d).', $status));
        }

        return new NfseReply($status, $body, true);
    }

    private static function url(FiscalEnvironment $environment, string $path): string
    {
        return rtrim((string) config("fiscal.nfse.base_urls.{$environment->value}"), '/').'/'.$path;
    }

    /** XML com declaração UTF-8, compactado em GZip e codificado em Base64. */
    private static function pack(string $xml): string
    {
        $document = str_starts_with($xml, '<?xml') ? $xml : '<?xml version="1.0" encoding="UTF-8"?>'.$xml;

        return base64_encode((string) gzencode($document));
    }
}
