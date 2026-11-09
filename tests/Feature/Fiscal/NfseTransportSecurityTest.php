<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalEnvironment;
use App\Exceptions\Fiscal\FiscalProviderUnavailable;
use App\Services\Fiscal\Nfse\NfseNacionalClient;
use App\Services\Fiscal\Nfse\Transport\GuzzleNfseNacionalTransport;
use App\Services\Fiscal\Nfse\Transport\NfseServerTrust;
use App\Services\Fiscal\Nfse\Transport\NfseTlsCredentials;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NFePHP\Common\Certificate;
use RuntimeException;
use Tests\Support\Fiscal\TestCertificate;
use Tests\TestCase;

/**
 * Transporte HTTPS da NFS-e nacional — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §10: servidor SEMPRE verificado, bundle configurado e
 * ausente falha fechado, chave privada em arquivo só cifrada, 0600, e apagada depois da chamada.
 * Nada aqui sai para a internet (`Http::fake`).
 */
class NfseTransportSecurityTest extends TestCase
{
    private Certificate $certificate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->certificate = Certificate::readPfx(TestCertificate::pfx('11222333000181'), TestCertificate::PASSWORD);
        config(['fiscal.nfse.temporary_path' => storage_path('framework/testing/nfse-tmp-'.uniqid())]);
    }

    public function test_server_verification_is_never_disabled(): void
    {
        $files = ['certificate' => '/c', 'key' => '/k', 'password' => 'x'];

        config(['fiscal.nfse.ca_bundle' => null]);
        $this->assertTrue(GuzzleNfseNacionalTransport::options($files, app(NfseServerTrust::class)->verifyOption())['verify']);

        $bundle = tempnam(sys_get_temp_dir(), 'ca');
        config(['fiscal.nfse.ca_bundle' => $bundle]);
        $this->assertSame($bundle, app(NfseServerTrust::class)->verifyOption());
        @unlink($bundle);
    }

    public function test_a_configured_but_missing_bundle_fails_closed_and_is_flagged(): void
    {
        config(['fiscal.nfse.ca_bundle' => '/nao/existe/icp-brasil.pem']);
        Http::fake();

        $this->assertSame(NfseServerTrust::MISSING_CA_BUNDLE, app(NfseServerTrust::class)->problem());
        $this->expectException(FiscalProviderUnavailable::class);

        try {
            app(GuzzleNfseNacionalTransport::class)->send(new \App\Services\Fiscal\Nfse\Transport\NfseHttpExchange('POST', 'https://sefin.example/nfse', ['a' => 'b']), $this->certificate);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_tls_files_are_private_the_key_is_encrypted_and_everything_is_deleted(): void
    {
        $seen = [];

        app(NfseTlsCredentials::class)->using($this->certificate, function (array $files) use (&$seen): void {
            $seen = $files;
            $this->assertSame('0600', substr(sprintf('%o', fileperms($files['key'])), -4));
            $this->assertSame('0600', substr(sprintf('%o', fileperms($files['certificate'])), -4));
            $this->assertStringContainsString('ENCRYPTED PRIVATE KEY', (string) file_get_contents($files['key']));
            $this->assertNotFalse(openssl_pkey_get_private((string) file_get_contents($files['key']), $files['password']));
            $this->assertSame('0700', substr(sprintf('%o', fileperms(dirname($files['key']))), -4));
        });

        $this->assertFileDoesNotExist($seen['key']);
        $this->assertFileDoesNotExist($seen['certificate']);
    }

    public function test_tls_files_are_deleted_even_when_the_call_fails(): void
    {
        $seen = [];

        try {
            app(NfseTlsCredentials::class)->using($this->certificate, function (array $files) use (&$seen): void {
                $seen = $files;
                throw new RuntimeException('falha no meio da chamada');
            });
        } catch (RuntimeException) {
        }

        $this->assertFileDoesNotExist($seen['key']);
    }

    public function test_the_client_posts_the_gzipped_dps_and_maps_server_errors_to_unavailable(): void
    {
        config(['fiscal.nfse.ca_bundle' => null]);
        Http::fake(['*/nfse' => Http::response(['erros' => [['codigo' => 'E0300', 'descricao' => 'x']]], 400), '*/dps/*' => Http::response('<html>bad gateway</html>', 502)]);
        $client = app(NfseNacionalClient::class);

        $reply = $client->submitDps($this->certificate, FiscalEnvironment::HOMOLOGATION, '<DPS/>');

        $this->assertSame(400, $reply->status);
        Http::assertSent(function (Request $request): bool {
            $xml = gzdecode(base64_decode((string) $request['dpsXmlGZipB64']));

            return $request->url() === 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional/nfse'
                && $xml === '<?xml version="1.0" encoding="UTF-8"?><DPS/>';
        });
        $this->expectException(FiscalProviderUnavailable::class);
        $client->findAccessKey($this->certificate, FiscalEnvironment::HOMOLOGATION, 'DPS1');
    }
}
