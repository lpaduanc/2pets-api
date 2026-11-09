<?php

namespace App\DataTransferObjects\Fiscal;

use App\Models\Service;

/**
 * Código do serviço que vai no `cServ` da DPS — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §5.3. Só guarda o que o XSD aceita: cTribNac com 6
 * dígitos, cTribMun com exatamente 3 dígitos (o cadastro aceita texto livre de até 20 — o que não
 * for 3 dígitos fica fora da DPS) e NBS com 9.
 *
 * Uma DPS tem UM `cServ`: a venda emite uma NFS-e por `key()` diferente.
 */
final readonly class NfseServiceCode
{
    public function __construct(
        public ?string $nationalTaxCode,
        public ?string $municipalTaxCode,
        public ?string $nbsCode,
    ) {}

    public static function fromService(?Service $service): self
    {
        return new self(
            self::digits($service?->national_tax_code, 6),
            self::digits($service?->municipal_service_code, 3),
            self::digits($service?->nbs_code, 9),
        );
    }

    /** @param  array<string, mixed>  $item  item congelado do documento */
    public static function fromItem(array $item): self
    {
        return new self($item['national_tax_code'] ?? null, $item['municipal_tax_code'] ?? null, $item['nbs_code'] ?? null);
    }

    /** Chave de agrupamento: linhas com a mesma chave cabem na mesma DPS. */
    public function key(): string
    {
        return implode('|', [$this->nationalTaxCode, $this->municipalTaxCode, $this->nbsCode]);
    }

    /** @return array{national_tax_code: string|null, municipal_tax_code: string|null, nbs_code: string|null} */
    public function toSnapshot(): array
    {
        return ['national_tax_code' => $this->nationalTaxCode, 'municipal_tax_code' => $this->municipalTaxCode, 'nbs_code' => $this->nbsCode];
    }

    private static function digits(?string $value, int $length): ?string
    {
        $trimmed = trim((string) $value);

        return preg_match('/^\d{'.$length.'}$/', $trimmed) === 1 ? $trimmed : null;
    }
}
