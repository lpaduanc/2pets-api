<?php

namespace App\DataTransferObjects\Location;

/**
 * Endereço de um CEP como a ViaCEP o descreve. CEP geral de cidade pequena não tem rua nem
 * bairro — por isso os dois são opcionais e cidade/UF não.
 */
final readonly class PostalAddress
{
    public function __construct(
        public string $zipCode,
        public ?string $street,
        public ?string $neighborhood,
        public string $city,
        public string $state,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  resposta de `viacep.com.br/ws/{cep}/json/`
     */
    public static function fromViaCep(array $payload): self
    {
        return new self(
            zipCode: preg_replace('/\D/', '', (string) $payload['cep']),
            street: self::presentOrNull($payload['logradouro'] ?? null),
            neighborhood: self::presentOrNull($payload['bairro'] ?? null),
            city: (string) $payload['localidade'],
            state: (string) $payload['uf'],
        );
    }

    /** Texto enviado ao geocoding: do mais específico ao mais geral, com o CEP no fim. */
    public function geocodingQuery(): string
    {
        return implode(', ', array_filter([
            $this->street,
            $this->neighborhood,
            "{$this->city} - {$this->state}",
            $this->formattedZipCode(),
            'Brasil',
        ]));
    }

    /** Recuo quando o endereço do CEP não geocodifica: o centro da cidade. */
    public function cityGeocodingQuery(): string
    {
        return "{$this->city} - {$this->state}, Brasil";
    }

    public function formattedZipCode(): string
    {
        return substr($this->zipCode, 0, 5).'-'.substr($this->zipCode, 5);
    }

    private static function presentOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
