<?php

namespace App\Support\Fiscal;

use App\Exceptions\Fiscal\FiscalSecretUnreadable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

/**
 * Cifra os segredos fiscais (A1, senha do A1, token do CSC) — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §3.3.
 *
 * Por que não o cast `encrypted` do Eloquent:
 * 1. **Chave própria** (`FISCAL_SECRETS_KEY`): o APP_KEY assina cookies/sessões e costuma ser
 *    rotacionado por outros motivos; rotacioná-lo não pode inutilizar os certificados, e vazar
 *    o APP_KEY não deve bastar para abri-los. Sem a chave própria configurada cai no APP_KEY —
 *    aceitável em dev, a spec exige a própria em produção.
 * 2. **Contexto amarrado** (ex.: `fiscal_certificates:42:pfx`): o texto cifrado carrega o
 *    contexto e a decifragem confere. Copiar o blob de uma organização para a linha de outra no
 *    banco não faz a outra assinar com o certificado alheio.
 *
 * AES-256-CBC + HMAC-SHA256 do `Encrypter` do Laravel (encrypt-then-MAC).
 */
final class FiscalSecretCipher
{
    private const CIPHER = 'AES-256-CBC';

    private ?Encrypter $encrypter = null;

    public function encrypt(#[\SensitiveParameter] string $plaintext, string $context): string
    {
        return $this->encrypter()->encryptString(json_encode([
            'context' => $context,
            'value' => base64_encode($plaintext),
        ], JSON_THROW_ON_ERROR));
    }

    /** @throws FiscalSecretUnreadable chave trocada, blob adulterado ou de outro contexto */
    public function decrypt(string $ciphertext, string $context): string
    {
        try {
            $envelope = json_decode($this->encrypter()->decryptString($ciphertext), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new FiscalSecretUnreadable('Segredo fiscal ilegível (chave de cifra trocada ou conteúdo adulterado).');
        }

        if (! hash_equals($context, (string) ($envelope['context'] ?? ''))) {
            throw new FiscalSecretUnreadable('Segredo fiscal pertence a outro contexto.');
        }

        return (string) base64_decode((string) $envelope['value'], true);
    }

    private function encrypter(): Encrypter
    {
        return $this->encrypter ??= new Encrypter($this->key(), self::CIPHER);
    }

    private function key(): string
    {
        $configured = (string) (config('fiscal.secrets.key') ?: config('app.key'));

        return Str::startsWith($configured, 'base64:') ? base64_decode(Str::after($configured, 'base64:')) : $configured;
    }
}
