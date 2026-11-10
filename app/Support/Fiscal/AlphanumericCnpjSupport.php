<?php

namespace App\Support\Fiscal;

use App\Enums\NfseLayout;
use App\Exceptions\Fiscal\NfeXmlInvalid;
use Composer\InstalledVersions;
use DOMDocument;
use DOMXPath;

/**
 * O leiaute fiscal configurado aceita CNPJ alfanumérico? — CNPJ alfanumérico (IN RFB 2.229/2024).
 *
 * NF-e/NFC-e: o pacote `PL_009_V4` do sped-nfe (padrão em `fiscal.sefaz.schema`) só aceita
 * `TCnpj = [0-9]{14}` e chave `[0-9]{44}`; os pacotes `PL_010_*` já trazem
 * `[0-9A-Z]{12}[0-9]{2}` (NT 2026.004 v1.01: homologação até 15/06/2026, produção 01/07/2026).
 * NFS-e: o XSD de produção (`v1.01-20260209`) só aceita `TSCNPJ = [0-9]{14}`; o da reforma
 * (`v1.01-rtc-20261020`, produção restrita) aceita `[0-9A-Z]{14}`.
 *
 * Em vez de uma lista fixa de pacotes, lê o padrão do tipo CNPJ no próprio XSD (pacote novo do
 * sped-nfe passa a valer sem mudar código). Leiaute que não aceita = falha FECHADA: a prontidão
 * mostra `cnpj_alphanumeric_schema` e a emissão vira rejeição local com o motivo, nunca um XML
 * que a SEFAZ/Sefin recusaria no schema.
 */
final class AlphanumericCnpjSupport
{
    public const MISSING_SCHEMA = 'cnpj_alphanumeric_schema';

    private const XSD_NAMESPACE = 'http://www.w3.org/2001/XMLSchema';

    private const LETTER_RANGE = 'A-Z';

    private const ALPHANUMERIC = '/[A-Z]/';

    /** @var array<string, bool> resultado por XSD/tipo (os arquivos não mudam durante o processo) */
    private static array $accepted = [];

    /** O pacote de schemas da NF-e/NFC-e em `fiscal.sefaz.schema` aceita CNPJ com letra. */
    public static function nfeSchemaAccepts(): bool
    {
        $schema = (string) config('fiscal.sefaz.schema');

        return self::xsdAccepts(InstalledVersions::getInstallPath('nfephp-org/sped-nfe')."/schemes/{$schema}/tiposBasico_v4.00.xsd", 'TCnpj');
    }

    public static function nfseLayoutAccepts(NfseLayout $layout): bool
    {
        return self::xsdAccepts($layout->schemaPath('tiposSimples_v1.01.xsd'), 'TSCNPJ');
    }

    /** Código de prontidão quando o CNPJ do emitente tem letra e o leiaute não aceita. */
    public static function problem(?string $cnpj, bool $layoutAccepts): ?string
    {
        return ! $layoutAccepts && preg_match(self::ALPHANUMERIC, (string) $cnpj) === 1 ? self::MISSING_SCHEMA : null;
    }

    /**
     * @param  array<string, string|null>  $values  rótulo => CNPJ/chave já normalizados
     *
     * @throws NfeXmlInvalid
     */
    public static function assertAccepted(array $values, bool $layoutAccepts, string $layout): void
    {
        $alphanumeric = array_keys(array_filter($values, fn (?string $value): bool => preg_match(self::ALPHANUMERIC, (string) $value) === 1));

        if ($layoutAccepts || $alphanumeric === []) {
            return;
        }

        throw NfeXmlInvalid::because([sprintf(
            'CNPJ alfanumérico (%s) não é aceito pelo leiaute fiscal configurado (%s). É preciso o leiaute que aceita CNPJ com letras antes de emitir este documento.',
            implode(', ', $alphanumeric),
            $layout,
        )]);
    }

    private static function xsdAccepts(string $path, string $type): bool
    {
        return self::$accepted["{$path}#{$type}"] ??= self::patternHasLetters($path, $type);
    }

    private static function patternHasLetters(string $path, string $type): bool
    {
        $document = new DOMDocument;

        // XSD ausente/ilegível = não aceita (falha fechada).
        if (! is_readable($path) || ! $document->load($path, LIBXML_NONET)) {
            return false;
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('xs', self::XSD_NAMESPACE);
        $patterns = $xpath->query("//xs:simpleType[@name='{$type}']//xs:pattern/@value");

        return $patterns !== false && $patterns->length > 0 && str_contains((string) $patterns->item(0)?->nodeValue, self::LETTER_RANGE);
    }
}
