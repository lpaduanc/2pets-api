<?php

namespace App\Services\Stock\Bulk;

use App\Models\Product;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Arquivo de carga da balança Toledo — `ITENSMGV.TXT` do MGV5 (PLANO 3.3). Uma linha por produto
 * vendido por peso com `scale_code`, campos de largura fixa, CRLF, ASCII sem acento:
 *
 *   pos  1-2   departamento            2  "01"
 *   pos  3     tipo de produto         1  "0" = venda por peso (kg)
 *   pos  4-9   código do item          6  `scale_code`, zeros à esquerda
 *   pos 10-15  preço por kg            6  centavos, zeros à esquerda (até 9.999,99)
 *   pos 16-18  dias de validade        3  "000" (sem validade impressa)
 *   pos 19-43  descritivo, linha 1     25 nome em maiúsculas, completado com espaço
 *   pos 44-68  descritivo, linha 2     25 continuação do nome (ou espaços)
 *   pos 69-74  código da informação extra 6 "000000"
 *
 * Fonte: layout do ITENSMGV.TXT do MGV5 da Toledo do Brasil, como implementado no componente
 * ACBrCargaBal (projeto ACBr) e nos manuais de integração do MGV. Confirmado pela TOTVS em "Como
 * estão dispostas as informações no ITENSMGV.txt conforme o layout oficial da Toledo" (central de
 * atendimento TOTVS): DD(2) T(1) CCCCCC(6) PPPPPP(6) VVV(3) D1(25) D2(25) + extras. Um fórum cita
 * uma versão antiga do MGV5 com descritivo único de 50; o MGV6/MGV7 aceita campos a mais no fim
 * da linha; a versão do MGV das clínicas-piloto ainda precisa ser confirmada
 * (`docs/PENDENCIAS.md`). Filizola e outros formatos ficam para depois.
 */
final class ToledoMgvExporter
{
    public const MAX_PRICE_CENTS = 999999;

    /** @param  Collection<int, Product>  $products */
    public function export(Collection $products): string
    {
        return $products
            ->filter(fn (Product $p): bool => $p->sold_by_weight && $p->scale_code !== null && $p->scale_code !== '')
            ->map(fn (Product $p): string => $this->line($p))
            ->implode("\r\n").($products->isEmpty() ? '' : "\r\n");
    }

    private function line(Product $product): string
    {
        $cents = min((int) Money::of($product->price ?? '0')->cents()->toInt(), self::MAX_PRICE_CENTS);
        $name = strtoupper(Str::ascii((string) $product->name));
        $name = preg_replace('/[^A-Z0-9 .,\-\/%]/', ' ', $name);

        return '01'
            .'0'
            .str_pad(ltrim((string) $product->scale_code, '0') ?: '0', 6, '0', STR_PAD_LEFT)
            .str_pad((string) $cents, 6, '0', STR_PAD_LEFT)
            .'000'
            .str_pad(substr($name, 0, 25), 25)
            .str_pad(substr($name, 25, 25), 25)
            .'000000';
    }
}
