<?php

namespace Tests\Unit\Fiscal;

use PHPUnit\Framework\TestCase;

/**
 * Bundle ICP-Brasil versionado (`resources/fiscal/ca/icp-brasil.pem`) — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §3.5. Garante que o arquivo baixado do ITI continua
 * íntegro: só certificados de AC, legíveis pelo OpenSSL, com as raízes vigentes conferidas por
 * impressão digital (SHA-256 publicada junto ao ACcompactado.zip de 2026-11-04).
 */
class IcpBrasilCaBundleTest extends TestCase
{
    /** Raízes ICP-Brasil que a SEFAZ usa (v10 hoje) e as demais vigentes. */
    private const ROOTS = [
        'Autoridade Certificadora Raiz Brasileira v5' => 'caa53fc6091c6951887c976e378f6ef89aa6377c55d97b6475422b71ed7e9b17',
        'Autoridade Certificadora Raiz Brasileira v10' => '6e0bff069a26994c15de2c4888cc54af84882e5495b7fbf66be9ccffec7489f6',
        'Autoridade Certificadora Raiz Brasileira v11' => '1406710058180fa4081aab3f246f1702429c552a11fa3143b84c88cb3ab8e5e7',
        'Autoridade Certificadora Raiz Brasileira v12' => 'd8478e37ce19c690cf657381e68fe600e4e1a042536830f06847e03e554c4b01',
    ];

    /** ACs SSL que emitem os certificados de servidor vistos nas SEFAZ em 2026-11-10. */
    private const SEFAZ_ISSUERS = ['Autoridade Certificadora do SERPRO SSLv1', 'AC SOLUTI SSL EV G4', 'AC Certisign ICP-Brasil SSL EV G4'];

    public function test_every_entry_is_a_readable_ca_certificate(): void
    {
        $certificates = self::certificates();

        $this->assertCount(28, $certificates);

        foreach ($certificates as $certificate) {
            $parsed = openssl_x509_parse($certificate);
            $this->assertIsArray($parsed);
            $this->assertStringContainsString('CA:TRUE', (string) ($parsed['extensions']['basicConstraints'] ?? ''), $parsed['subject']['CN'] ?? '?');
        }
    }

    public function test_the_icp_brasil_roots_are_present_and_self_signed(): void
    {
        $roots = [];

        foreach (self::certificates() as $certificate) {
            $parsed = openssl_x509_parse($certificate);

            if ($parsed['subject'] === $parsed['issuer']) {
                $roots[$parsed['subject']['CN']] = openssl_x509_fingerprint($certificate, 'sha256');
            }
        }

        ksort($roots);
        $expected = self::ROOTS;
        ksort($expected);
        $this->assertSame($expected, $roots);
    }

    public function test_the_ssl_issuers_seen_on_sefaz_hosts_chain_to_a_root_in_the_bundle(): void
    {
        $bundle = self::path();

        foreach (self::certificates() as $certificate) {
            $commonName = openssl_x509_parse($certificate)['subject']['CN'];

            if (in_array($commonName, self::SEFAZ_ISSUERS, true)) {
                $this->assertTrue(openssl_x509_checkpurpose($certificate, X509_PURPOSE_ANY, [$bundle]), $commonName);
            }
        }

        $names = array_map(fn (string $pem): string => openssl_x509_parse($pem)['subject']['CN'], self::certificates());
        $this->assertSame([], array_values(array_diff(self::SEFAZ_ISSUERS, $names)));
    }

    /** @return list<string> */
    private static function certificates(): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', (string) file_get_contents(self::path()), $matches);

        return $matches[0];
    }

    private static function path(): string
    {
        return dirname(__DIR__, 3).'/resources/fiscal/ca/icp-brasil.pem';
    }
}
