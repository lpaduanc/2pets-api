<?php

namespace App\Enums;

/**
 * Leiaute da DPS 1.01 em uso no ambiente — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §9. Os dois pacotes de XSD oficiais declaram
 * `versao="1.01"`, mas a produção restrita (2026-10-20) já traz as mudanças das NTs 005/009:
 * `finNFSe` obrigatório no `infDPS` e `xDescServ` movido para o fim do `cServ`.
 */
enum NfseLayout: string
{
    case PRODUCTION_20260209 = 'v1.01-20260209';
    case REFORM_20261020 = 'v1.01-rtc-20261020';

    public const VERSION = '1.01';

    public static function forEnvironment(FiscalEnvironment $environment): self
    {
        return self::from((string) config("fiscal.nfse.layouts.{$environment->value}"));
    }

    /** `infDPS/finNFSe` (0 = NFS-e regular) — exigido só no leiaute da reforma. */
    public function hasFinality(): bool
    {
        return $this === self::REFORM_20261020;
    }

    /** No leiaute da reforma `xDescServ` vem depois de `cNBS`/`cIntContrib`; no de produção, antes. */
    public function describesServiceLast(): bool
    {
        return $this === self::REFORM_20261020;
    }

    public function schemaPath(string $file): string
    {
        return resource_path("fiscal/nfse/schemas/{$this->value}/{$file}");
    }
}
