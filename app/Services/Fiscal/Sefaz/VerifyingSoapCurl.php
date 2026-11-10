<?php

namespace App\Services\Fiscal\Sefaz;

use LogicException;
use NFePHP\Common\Certificate;
use NFePHP\Common\Exception\SoapException;
use NFePHP\Common\Soap\SoapBase;
use NFePHP\Common\Soap\SoapInterface;
use NFePHP\Common\Validator;

/**
 * Transporte SOAP 1.2 com mTLS até a SEFAZ que VERIFICA o servidor — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §3.5 (ameaça T9).
 *
 * Por que não o `SoapCurl` do sped-common: o `send()` dele fixa `CURLOPT_SSL_VERIFYPEER = 0`
 * (mesmo com `disableSecurity(false)` só o host volta a ser checado), o que permite MITM com
 * qualquer certificado. A montagem do pedido abaixo segue o `NFePHP\Common\Soap\SoapCurl::send()`
 * do sped-common 5.1.17 (Roberto L. Machado, MIT/LGPL), trocando só o que importa:
 * VERIFYPEER ligado, VERIFYHOST 2, `CAINFO` = sistema + ICP-Brasil (`SefazServerTrust`) e
 * apenas HTTPS. Sem bundle legível nada sai (`FiscalProviderUnavailable`), nem a chave vai ao disco.
 *
 * As mitigações da chave privada temporária (ameaça T5) continuam do `SoapBase`: chave cifrada
 * com senha de uso único, pasta privada e remoção no destrutor.
 */
class VerifyingSoapCurl extends SoapBase implements SoapInterface
{
    private const VERIFY_HOST_NAME = 2;

    /** Mesma folga do sped-common: o tempo total é o de conexão + 20 s de leitura. */
    private const EXTRA_READ_SECONDS = 20;

    private const HTTP_OK = 200;

    /** Códigos que o sped-common usa para "sem conexão" e "resposta vazia/inválida". */
    private const CODE_NO_CONNECTION = 7;

    private const CODE_INVALID_REPLY = 99;

    public function __construct(Certificate $certificate, private readonly SefazServerTrust $trust)
    {
        parent::__construct($certificate);
    }

    /** A verificação do servidor não é desligável neste transporte. */
    public function disableSecurity($flag = false): bool
    {
        if ($flag) {
            throw new LogicException('A verificação TLS do servidor da SEFAZ não pode ser desligada.');
        }

        return $this->disablesec = false;
    }

    /**
     * @param  string  $url
     * @param  string  $operation
     * @param  string  $action
     * @param  int  $soapver
     * @param  array<int|string, mixed>  $parameters  ignorado (como no sped-common: os cabeçalhos são recalculados)
     * @param  array<string, string>  $namespaces
     * @param  string  $request
     * @param  \SoapHeader|null  $soapheader
     *
     * @throws SoapException
     * @throws \App\Exceptions\Fiscal\FiscalProviderUnavailable
     */
    public function send($url, $operation = '', $action = '', $soapver = SOAP_1_2, $parameters = [], $namespaces = [], $request = '', $soapheader = null): string
    {
        // Antes de gravar a chave: sem bundle, nem o arquivo temporário chega a existir.
        $bundle = $this->trust->bundlePath();
        $this->saveTemporarilyKeyFiles();
        $envelope = $this->makeEnvelopeSoap($request, $namespaces, $soapver, $soapheader);
        $headers = self::headers($envelope, (string) $action);
        $this->requestHead = implode("\n", $headers);
        $this->requestBody = $envelope;

        $reply = $this->execute($this->curlOptions((string) $url, $envelope, $headers, $bundle));
        $this->keepReply($reply, (string) $operation);
        $this->assertSucceeded((string) $url, (int) ($reply['info']['http_code'] ?? 0));

        return (string) $this->responseBody;
    }

    /**
     * Executa o cURL. Protegido para o teste inspecionar as opções sem tocar a rede.
     *
     * @param  array<int, mixed>  $options
     * @return array{body: string|false, error: string, errno: int, info: array<string, mixed>}
     *
     * @throws SoapException
     */
    protected function execute(array $options): array
    {
        $handle = curl_init();

        // Opção recusada pelo cURL (ex.: CAINFO) não pode virar conexão sem verificação.
        if ($handle === false || ! curl_setopt_array($handle, $options)) {
            throw SoapException::unableToLoadCurl('Opções TLS recusadas pelo cURL; nada foi enviado.');
        }

        $body = curl_exec($handle);

        return ['body' => $body, 'error' => curl_error($handle), 'errno' => curl_errno($handle), 'info' => (array) curl_getinfo($handle)];
    }

    /**
     * @param  list<string>  $headers
     * @return array<int, mixed>
     */
    private function curlOptions(string $url, string $envelope, array $headers, string $bundle): array
    {
        return [
            CURLOPT_URL => $url,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_CONNECTTIMEOUT => $this->soaptimeout,
            CURLOPT_TIMEOUT => $this->soaptimeout + self::EXTRA_READ_SECONDS,
            CURLOPT_HEADER => true,
            CURLOPT_HTTP_VERSION => $this->httpver,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => self::VERIFY_HOST_NAME,
            CURLOPT_CAINFO => $bundle,
            CURLOPT_SSLVERSION => $this->soapprotocol,
            CURLOPT_SSLCERT => $this->tempdir.$this->certfile,
            CURLOPT_SSLKEY => $this->tempdir.$this->prifile,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $envelope,
            CURLOPT_HTTPHEADER => $headers,
        ] + $this->optionalOptions();
    }

    /** @return array<int, mixed> senha da chave temporária, nível de cifra e proxy, quando houver */
    private function optionalOptions(): array
    {
        return array_filter([
            CURLOPT_KEYPASSWD => $this->temppass === '' ? null : $this->temppass,
            CURLOPT_SSL_CIPHER_LIST => empty($this->security_level) ? null : $this->security_level,
            CURLOPT_HTTPPROXYTUNNEL => empty($this->proxyIP) ? null : true,
            CURLOPT_PROXYTYPE => empty($this->proxyIP) ? null : CURLPROXY_HTTP,
            CURLOPT_PROXY => empty($this->proxyIP) ? null : "{$this->proxyIP}:{$this->proxyPort}",
            CURLOPT_PROXYUSERPWD => empty($this->proxyIP) || empty($this->proxyUser) ? null : "{$this->proxyUser}:{$this->proxyPass}",
            CURLOPT_PROXYAUTH => empty($this->proxyIP) || empty($this->proxyUser) ? null : CURLAUTH_BASIC,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @param  array{body: string|false, error: string, errno: int, info: array<string, mixed>}  $reply */
    private function keepReply(array $reply, string $operation): void
    {
        $raw = (string) $reply['body'];
        $headerSize = (int) ($reply['info']['header_size'] ?? 0);
        $this->soaperror = $reply['error'];
        $this->soaperror_code = $reply['errno'];
        $this->soapinfo = $reply['info'];
        $this->responseHead = trim(substr($raw, 0, $headerSize));
        $this->responseBody = trim(substr($raw, $headerSize));
        $this->saveDebugFiles($operation, $this->requestHead."\n".$this->requestBody, $this->responseHead."\n".$this->responseBody);
    }

    /** @throws SoapException falha de rede/TLS (ex.: cURL 60, cadeia não confiável), HTTP ≠ 200 ou resposta que não é XML */
    private function assertSucceeded(string $url, int $httpCode): void
    {
        if ($this->soaperror !== '') {
            throw SoapException::soapFault("{$this->soaperror} [{$url}]", $this->soaperror_code ?: self::CODE_NO_CONNECTION);
        }

        if ($httpCode !== self::HTTP_OK) {
            throw SoapException::soapFault("HTTP {$httpCode} [{$url}]", $httpCode);
        }

        // O corpo não vai para a mensagem: pode ser uma página HTML inteira de erro do servidor.
        if ($this->responseBody === '' || ! Validator::isXML($this->responseBody)) {
            throw SoapException::soapFault("Retorno da SEFAZ vazio ou não é XML [{$url}]", self::CODE_INVALID_REPLY);
        }
    }

    /** @return list<string> */
    private static function headers(string $envelope, string $action): array
    {
        $contentType = 'Content-Type: application/soap+xml;charset=utf-8;'.($action === '' ? '' : "action={$action}");

        return [$contentType, 'Content-length: '.strlen($envelope)];
    }
}
