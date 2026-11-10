<?php

namespace Tests\Feature\Fiscal;

use App\Exceptions\Fiscal\FiscalProviderUnavailable;
use App\Services\Fiscal\Sefaz\CurlSefazTransportFactory;
use App\Services\Fiscal\Sefaz\SefazServerTrust;
use App\Services\Fiscal\Sefaz\SefazTransportFactory;
use App\Services\Fiscal\Sefaz\VerifyingSoapCurl;
use Illuminate\Support\Facades\File;
use LogicException;
use NFePHP\Common\Certificate;
use NFePHP\Common\Exception\SoapException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fiscal\TestCertificate;
use Tests\TestCase;

/**
 * Transporte SOAP até a SEFAZ — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md
 * §3.5 (ameaça T9): servidor SEMPRE verificado com sistema + ICP-Brasil, bundle ausente falha
 * fechado, chave privada temporária só cifrada. Nada sai para a rede: o cURL é interceptado no
 * `execute()` (as opções ficam em `$options`).
 */
class SefazTransportSecurityTest extends TestCase
{
    private const SOAP_REPLY = "HTTP/1.1 200 OK\r\nContent-Type: application/soap+xml\r\n\r\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\"><env:Body><ok/></env:Body></env:Envelope>";

    private Certificate $certificate;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->certificate = Certificate::readPfx(TestCertificate::pfx('11222333000181'), TestCertificate::PASSWORD);
        $this->folder = storage_path('framework/testing/sefaz-'.uniqid());
        config(['fiscal.sefaz.temporary_path' => $this->folder.'/tmp', 'fiscal.sefaz.trust_path' => $this->folder.'/ca']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        parent::tearDown();
    }

    public function test_the_real_factory_uses_the_verifying_transport(): void
    {
        $this->assertInstanceOf(CurlSefazTransportFactory::class, app(SefazTransportFactory::class));
        $this->assertInstanceOf(VerifyingSoapCurl::class, app(SefazTransportFactory::class)->make($this->certificate));
    }

    public function test_peer_and_host_are_verified_against_the_combined_bundle(): void
    {
        $transport = $this->transport();

        $reply = $transport->send('https://homologacao.nfe.sefaz.example/ws/NFeAutorizacao4.asmx', 'nfeAutorizacaoLote', 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeAutorizacao4', SOAP_1_2, [], [], '<enviNFe/>');

        $options = $transport->options;
        $this->assertStringContainsString('<ok/>', $reply);
        $this->assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        $this->assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        $this->assertSame(app(SefazServerTrust::class)->bundlePath(), $options[CURLOPT_CAINFO]);
        $this->assertStringStartsWith($this->folder.'/ca/sefaz-trust-', $options[CURLOPT_CAINFO]);
    }

    public function test_the_combined_bundle_holds_the_system_store_and_the_icp_brasil_chains(): void
    {
        $system = $this->folder.'/system.pem';
        File::ensureDirectoryExists($this->folder);
        $systemPem = (string) file_get_contents(base_path('resources/fiscal/ca/icp-brasil.pem'));
        file_put_contents($system, "# trust store do sistema (simulado)\n".self::firstCertificate($systemPem));
        config(['fiscal.sefaz.system_ca_bundle' => $system]);

        $combined = (string) file_get_contents(app(SefazServerTrust::class)->bundlePath());

        $this->assertStringContainsString('trust store do sistema (simulado)', $combined);
        $this->assertStringContainsString('Autoridade Certificadora Raiz Brasileira v10', $combined);
        $this->assertSame(substr_count($systemPem, 'BEGIN CERTIFICATE') + 1, substr_count($combined, 'BEGIN CERTIFICATE'));
    }

    public function test_a_changed_bundle_produces_a_new_combined_file(): void
    {
        $system = $this->folder.'/system.pem';
        File::ensureDirectoryExists($this->folder);
        $certificate = self::firstCertificate((string) file_get_contents(base_path('resources/fiscal/ca/icp-brasil.pem')));
        file_put_contents($system, $certificate);
        config(['fiscal.sefaz.system_ca_bundle' => $system]);
        $before = app(SefazServerTrust::class)->bundlePath();

        file_put_contents($system, $certificate."\n".$certificate);

        $this->assertNotSame($before, app(SefazServerTrust::class)->bundlePath());
    }

    public function test_the_temporary_private_key_is_encrypted_and_inside_the_private_folder(): void
    {
        $transport = $this->transport();
        $transport->send('https://sefaz.example/ws', 'op', '', SOAP_1_2, [], [], '<consSitNFe/>');

        $key = (string) $transport->options[CURLOPT_SSLKEY];
        $this->assertStringStartsWith($this->folder.'/tmp/', $key);
        $this->assertStringContainsString('ENCRYPTED PRIVATE KEY', (string) file_get_contents($key));
        $this->assertNotEmpty($transport->options[CURLOPT_KEYPASSWD]);
    }

    /** @return array<string, array{0: string}> */
    public static function brokenBundles(): array
    {
        return [
            'ICP-Brasil ausente' => ['fiscal.sefaz.ca_bundle'],
            'trust store do sistema ausente' => ['fiscal.sefaz.system_ca_bundle'],
        ];
    }

    #[DataProvider('brokenBundles')]
    public function test_a_missing_bundle_fails_closed_before_anything_is_written_or_sent(string $setting): void
    {
        config([$setting => '/nao/existe/cadeia.pem']);
        $transport = $this->transport();

        $this->assertSame(SefazServerTrust::MISSING_CA_BUNDLE, app(SefazServerTrust::class)->problem());

        try {
            $transport->send('https://sefaz.example/ws', 'op', '', SOAP_1_2, [], [], '<enviNFe/>');
            $this->fail('Envio sem bundle de CAs deveria falhar.');
        } catch (FiscalProviderUnavailable $unavailable) {
            $this->assertStringContainsString($setting, $unavailable->getMessage());
        }

        $this->assertSame([], $transport->options, 'o cURL não pode ter sido chamado');
        $this->assertSame([], File::files($this->folder.'/tmp'), 'nenhuma chave pode ter ido ao disco');
    }

    public function test_a_bundle_without_certificates_is_treated_as_missing(): void
    {
        File::ensureDirectoryExists($this->folder);
        file_put_contents($this->folder.'/vazio.pem', "nada aqui\n");
        config(['fiscal.sefaz.ca_bundle' => $this->folder.'/vazio.pem']);

        $this->assertSame(SefazServerTrust::MISSING_CA_BUNDLE, app(SefazServerTrust::class)->problem());
    }

    public function test_tls_failures_surface_as_soap_faults(): void
    {
        $transport = $this->transport(reply: ['body' => false, 'error' => 'SSL certificate problem: unable to get local issuer certificate', 'errno' => 60, 'info' => ['http_code' => 0, 'header_size' => 0]]);

        $this->expectException(SoapException::class);
        $this->expectExceptionMessage('SSL certificate problem');

        $transport->send('https://sefaz.example/ws', 'op', '', SOAP_1_2, [], [], '<enviNFe/>');
    }

    public function test_server_verification_cannot_be_disabled(): void
    {
        $this->expectException(LogicException::class);

        $this->transport()->disableSecurity(true);
    }

    /** @param  array{body: string|false, error: string, errno: int, info: array<string, mixed>}|null  $reply */
    private function transport(?array $reply = null): VerifyingSoapCurl
    {
        $reply ??= ['body' => self::SOAP_REPLY, 'error' => '', 'errno' => 0, 'info' => ['http_code' => 200, 'header_size' => strpos(self::SOAP_REPLY, "\r\n\r\n") + 4]];
        File::ensureDirectoryExists($this->folder.'/tmp', 0700);
        $transport = new class($this->certificate, app(SefazServerTrust::class), $reply) extends VerifyingSoapCurl
        {
            /** @var array<int, mixed> */
            public array $options = [];

            /** @param  array{body: string|false, error: string, errno: int, info: array<string, mixed>}  $reply */
            public function __construct(Certificate $certificate, SefazServerTrust $trust, private readonly array $reply)
            {
                parent::__construct($certificate, $trust);
            }

            protected function execute(array $options): array
            {
                $this->options = $options;

                return $this->reply;
            }
        };
        $transport->setEncriptPrivateKey(true);
        $transport->setTemporaryFolder($this->folder.'/tmp');

        return $transport;
    }

    private static function firstCertificate(string $pem): string
    {
        preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $match);

        return $match[0]."\n";
    }
}
