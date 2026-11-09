<?php

namespace App\Services\Fiscal\Nfse\Transport;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NFePHP\Common\Certificate;
use RuntimeException;

/**
 * Arquivos do mTLS durante UMA chamada — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §10 (mesmas mitigações da fase A, ameaça T5):
 *
 * - o cURL exige certificado e chave em ARQUIVO: a chave vai cifrada (AES-256) com uma senha
 *   aleatória de uso único que só existe em memória;
 * - pasta privada `fiscal.nfse.temporary_path` (0700), arquivos 0600, nunca o /tmp compartilhado;
 * - apagados no `finally`, com ou sem erro.
 */
final class NfseTlsCredentials
{
    private const FOLDER_MODE = 0700;

    private const FILE_MODE = 0600;

    private const ONE_TIME_PASSWORD_LENGTH = 32;

    /**
     * @template TResult
     *
     * @param  Closure(array{certificate: string, key: string, password: string}): TResult  $call
     * @return TResult
     */
    public function using(Certificate $certificate, Closure $call): mixed
    {
        $folder = (string) config('fiscal.nfse.temporary_path');
        File::ensureDirectoryExists($folder, self::FOLDER_MODE);
        $password = Str::random(self::ONE_TIME_PASSWORD_LENGTH);
        $files = ['certificate' => $this->path($folder, 'crt'), 'key' => $this->path($folder, 'key')];

        try {
            $this->write($files['certificate'], (string) $certificate->publicKey);
            $this->write($files['key'], $this->encryptedKey($certificate, $password));

            return $call($files + ['password' => $password]);
        } finally {
            File::delete(array_values($files));
        }
    }

    private function encryptedKey(Certificate $certificate, #[\SensitiveParameter] string $password): string
    {
        $exported = '';

        if (! openssl_pkey_export((string) $certificate->privateKey, $exported, $password, ['encrypt_key_cipher' => OPENSSL_CIPHER_AES_256_CBC])) {
            // A mensagem do OpenSSL pode citar o material da chave: descartada de propósito.
            throw new RuntimeException('Não foi possível preparar a chave do certificado para a conexão.');
        }

        return $exported;
    }

    private function write(string $path, #[\SensitiveParameter] string $contents): void
    {
        touch($path);
        chmod($path, self::FILE_MODE);
        file_put_contents($path, $contents);
    }

    private function path(string $folder, string $extension): string
    {
        return sprintf('%s/nfse-%s.%s', rtrim($folder, '/'), Str::random(24), $extension);
    }
}
