<?php

namespace Tests\Support\Fiscal;

/**
 * Certificado A1 de TESTE gerado na hora com OpenSSL (autoassinado) — nunca um certificado real.
 * Leva o CNPJ no OID ICP-Brasil 2.16.76.1.3.3 (otherName do subjectAltName), onde o sped-common o
 * procura. A chave RSA é gerada uma vez por processo (gerar 2048 bits em todo teste é lento).
 */
final class TestCertificate
{
    public const PASSWORD = 'senha-de-teste-123';

    private static mixed $key = null;

    /** @return string bytes do .pfx */
    public static function pfx(string $cnpj, string $password = self::PASSWORD, int $validDays = 365): string
    {
        $config = self::config($cnpj);
        $key = self::key();
        $csr = openssl_csr_new(['commonName' => "CLINICA TESTE LTDA:{$cnpj}", 'organizationName' => 'ICP-Brasil', 'countryName' => 'BR'], $key, $config);
        $certificate = openssl_csr_sign($csr, null, $key, $validDays, $config, random_int(1000, 999999));
        openssl_pkcs12_export($certificate, $pfx, $key, $password);
        @unlink($config['config']);

        return $pfx;
    }

    private static function key(): mixed
    {
        return self::$key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    /** @return array<string, mixed> */
    private static function config(string $cnpj): array
    {
        $file = tempnam(sys_get_temp_dir(), 'a1-test');
        file_put_contents($file, "[req]\ndistinguished_name=dn\n[dn]\n[ext]\nsubjectAltName=otherName:2.16.76.1.3.3;UTF8:{$cnpj}\nkeyUsage=digitalSignature,nonRepudiation,keyEncipherment\n");

        return ['config' => $file, 'x509_extensions' => 'ext', 'digest_alg' => 'sha256'];
    }
}
