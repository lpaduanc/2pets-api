<?php

namespace App\Support\Fiscal;

use Illuminate\Support\Str;

/**
 * Código IBGE do município (cMun/cMunFG da NF-e) a partir de nome + UF — spec docs/gap-petflow/
 * specs/fase4-transmissao-propria-nfe-nfce.md §5.3. O cadastro de clientes/fornecedores guarda só
 * o nome da cidade; a tabela vem do IBGE (`resources/fiscal/ibge-municipios.csv`, 5.571
 * municípios, API de localidades). Comparação sem acento, caixa, hífen nem apóstrofo.
 */
final class IbgeMunicipalities
{
    private const SOURCE = 'fiscal/ibge-municipios.csv';

    /** @var array<string, string>|null  "UF|nome normalizado" => código */
    private static ?array $index = null;

    public function code(?string $city, ?string $state): ?string
    {
        if (blank($city) || blank($state)) {
            return null;
        }

        return self::index()[self::key((string) $state, (string) $city)] ?? null;
    }

    /** @return array<string, string> */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $handle = fopen(resource_path(self::SOURCE), 'r');
        fgetcsv($handle, separator: ';', escape: '');
        $index = [];

        while (($row = fgetcsv($handle, separator: ';', escape: '')) !== false) {
            $index[self::key($row[1], $row[2])] = $row[0];
        }

        fclose($handle);

        return self::$index = $index;
    }

    private static function key(string $state, string $city): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($city))));

        return strtoupper(trim($state)).'|'.$name;
    }
}
