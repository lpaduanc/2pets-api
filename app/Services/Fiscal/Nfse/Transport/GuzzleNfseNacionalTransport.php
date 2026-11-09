<?php

namespace App\Services\Fiscal\Nfse\Transport;

use Illuminate\Http\Client\Factory;
use NFePHP\Common\Certificate;

/**
 * Transporte real (Guzzle pelo HTTP client do Laravel) — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §10: mTLS com o A1 da organização (arquivos
 * temporários de `NfseTlsCredentials`) e o servidor SEMPRE verificado (`NfseServerTrust`).
 * Não loga corpo nem cabeçalho: o pedido leva dado fiscal do cliente.
 */
final class GuzzleNfseNacionalTransport implements NfseNacionalTransport
{
    public function __construct(
        private readonly Factory $http,
        private readonly NfseTlsCredentials $credentials,
        private readonly NfseServerTrust $trust,
    ) {}

    public function send(NfseHttpExchange $request, Certificate $certificate): NfseHttpExchange
    {
        $verify = $this->trust->verifyOption();

        return $this->credentials->using($certificate, function (array $files) use ($request, $verify): NfseHttpExchange {
            $response = $this->http
                ->withOptions(self::options($files, $verify))
                ->acceptJson()
                ->send($request->method, $request->url, $request->json === null ? [] : ['json' => $request->json]);

            return $request->answered($response->status(), $response->body());
        });
    }

    /**
     * @param  array{certificate: string, key: string, password: string}  $files
     * @return array<string, mixed>
     */
    public static function options(array $files, string|bool $verify): array
    {
        return [
            'cert' => $files['certificate'],
            'ssl_key' => [$files['key'], $files['password']],
            'verify' => $verify,
            'timeout' => (int) config('fiscal.nfse.timeout_seconds'),
            'connect_timeout' => (int) config('fiscal.nfse.connect_timeout_seconds'),
        ];
    }
}
