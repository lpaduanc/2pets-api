<?php

namespace App\Services\Fiscal\Nfse;

/**
 * Resposta JSON da Sefin Nacional — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §1.1-1/§6. Os nomes dos campos não constam do manual
 * oficial (o Swagger exige mTLS): a leitura é tolerante a caixa (`erros`/`Erros`,
 * `codigo`/`Codigo`) e aceita erro único ou lista.
 */
final readonly class NfseReply
{
    private const ERROR_KEYS = ['erros', 'erro'];

    private const ALERT_KEYS = ['alertas', 'alerta'];

    /** @param  array<string, mixed>  $body */
    public function __construct(public int $status, public array $body, public bool $isJson) {}

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function nfseXml(): ?string
    {
        return self::unpack($this->field('nfseXmlGZipB64'));
    }

    public function eventXml(): ?string
    {
        return self::unpack($this->field('eventoXmlGZipB64'));
    }

    public function accessKey(): ?string
    {
        $key = $this->field('chaveAcesso');

        return is_string($key) && preg_match(NfseDocumentReader::ACCESS_KEY_PATTERN, $key) === 1 ? $key : null;
    }

    /** @return list<array{code: string, description: string, complement: string}> */
    public function errors(): array
    {
        return array_map(fn (array $error): array => [
            'code' => trim((string) self::lookup($error, 'codigo')),
            'description' => trim((string) self::lookup($error, 'descricao')),
            'complement' => trim((string) self::lookup($error, 'complemento')),
        ], self::listOf($this->firstField(self::ERROR_KEYS)));
    }

    /** @return list<string> alertas legíveis ("código - descrição") */
    public function alerts(): array
    {
        return array_values(array_filter(array_map(
            fn (array $alert): string => trim(self::lookup($alert, 'codigo').' - '.self::lookup($alert, 'descricao'), ' -'),
            self::listOf($this->firstField(self::ALERT_KEYS)),
        )));
    }

    public function field(string $name): mixed
    {
        return self::lookup($this->body, $name);
    }

    /** @param  list<string>  $names */
    private function firstField(array $names): mixed
    {
        foreach ($names as $name) {
            $value = $this->field($name);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** Campo por nome, sem diferenciar maiúscula de minúscula. */
    private static function lookup(array $source, string $name): mixed
    {
        foreach ($source as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private static function listOf(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            return [];
        }

        return array_is_list($value) ? array_values(array_filter($value, 'is_array')) : [$value];
    }

    /** GZip + Base64 → XML; corrompido = nulo (quem chama decide). */
    private static function unpack(mixed $packed): ?string
    {
        $binary = is_string($packed) ? base64_decode($packed, true) : false;
        $xml = $binary === false ? false : @gzdecode($binary);

        return is_string($xml) && $xml !== '' ? $xml : null;
    }
}
