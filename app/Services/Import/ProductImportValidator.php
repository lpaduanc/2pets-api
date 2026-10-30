<?php

namespace App\Services\Import;

use App\Contracts\Import\ImportRowValidator;
use App\Models\DataImport;
use App\Services\Stock\StockValuation;
use App\Support\Money\Decimal;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use InvalidArgumentException;

/**
 * Valida/normaliza uma linha de importação de produtos (item 26 do backlog gap-simplesvet).
 * Preço/markup seguem a mesma exigência de `StoreProductRequest` (preço OU markup, nunca
 * nenhum dos dois) — a fórmula de conversão entre eles continua sendo só de `PricingService`,
 * aplicada no provisionamento, não aqui.
 */
final class ProductImportValidator implements ImportRowValidator
{
    public function __construct(
        private readonly BrazilianFormatParser $formatParser,
        private readonly ProductCatalogResolver $catalogResolver,
    ) {}

    /**
     * @param  array<string, string>  $row
     * @return array{valid: bool, normalized: array<string, mixed>, errors: list<string>}
     */
    public function validate(array $row, DataImport $import): array
    {
        $errors = [];
        $name = trim($row['name'] ?? '');
        if ($name === '') {
            $errors[] = 'Nome do produto é obrigatório.';
        }

        $price = $this->parseUnitValue($row['price'] ?? '', 'Preço', $errors);
        $averageCost = $this->parseUnitValue($row['average_cost'] ?? '', 'Custo', $errors, StockValuation::COST_SCALE);
        $markupPercent = $this->parseOptionalDecimal($row['markup_percent'] ?? '', 'Markup', $errors);
        if ($price === null && $markupPercent === null) {
            $errors[] = 'Informe o preço de venda ou um markup para calculá-lo a partir do custo.';
        }

        return [
            'valid' => $errors === [],
            'normalized' => [
                'name' => $name,
                'code' => trim($row['code'] ?? '') ?: null,
                'sku' => trim($row['sku'] ?? '') ?: null,
                'gtin' => trim($row['gtin'] ?? '') ?: null,
                'ncm' => trim($row['ncm'] ?? '') ?: null,
                'unit_of_sale' => trim($row['unit_of_sale'] ?? '') ?: null,
                'brand_id' => $this->catalogResolver->resolveBrand($row['brand'] ?? null, $import->user),
                'product_group_id' => $this->catalogResolver->resolveGroup($row['product_group'] ?? null, $import->user),
                'price' => $price,
                'average_cost' => $averageCost,
                'markup_percent' => $markupPercent,
                'stock_quantity' => $this->parseStockQuantity($row['stock_quantity'] ?? '', $errors),
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<string>  $errors
     */
    private function parseOptionalDecimal(string $rawValue, string $fieldLabel, array &$errors): ?float
    {
        if (trim($rawValue) === '') {
            return null;
        }

        $value = $this->formatParser->parseDecimal($rawValue);
        if ($value === null) {
            $errors[] = "{$fieldLabel} inválido.";
        }

        return $value;
    }

    /**
     * Saldo exato da planilha (até 4 casas) — antes era `round()` para inteiro: 1,25 kg virava 1.
     * Mais casas do que o estoque guarda é erro da linha, nunca arredondamento.
     *
     * @param  list<string>  $errors
     */
    private function parseStockQuantity(string $rawQuantity, array &$errors): string
    {
        $quantity = $this->formatParser->parseDecimalString($rawQuantity);

        if ($quantity === null) {
            return '0';
        }

        try {
            return Quantity::of($quantity)->max(0)->toString();
        } catch (InvalidArgumentException) {
            $errors[] = 'Estoque com mais de 4 casas decimais.';

            return '0';
        }
    }

    /**
     * Preço e custo unitário com no máximo 2 casas (regra do dono do produto); mais é erro da linha.
     *
     * @param  list<string>  $errors
     */
    private function parseUnitValue(string $rawValue, string $fieldLabel, array &$errors, int $maxScale = Money::SCALE): ?string
    {
        if (trim($rawValue) === '') {
            return null;
        }

        $value = $this->formatParser->parseDecimalString($rawValue);

        if ($value === null || Decimal::of($value)->strippedOfTrailingZeros()->getScale() > $maxScale) {
            $errors[] = "{$fieldLabel} inválido.";

            return null;
        }

        return $value;
    }
}
