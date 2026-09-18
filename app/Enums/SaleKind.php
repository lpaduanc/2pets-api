<?php

namespace App\Enums;

/**
 * `sales.kind` — venda ou orçamento.
 *
 * Decisão herdada do doc 24 ("orçamento é `sales.kind = 'quote'`, não tabela nova"): itens,
 * desconto, totalização e impressão são idênticos; o que muda é só o EFEITO COLATERAL —
 * orçamento não movimenta caixa nem estoque. Duplicar a tabela duplicaria as quatro coisas
 * iguais para variar a única diferente.
 */
enum SaleKind: string
{
    case SALE = 'sale';
    case QUOTE = 'quote';

    public function label(): string
    {
        return match ($this) {
            self::SALE => 'Venda',
            self::QUOTE => 'Orçamento',
        };
    }

    /** Critério de aceite do doc 01: "Orçamento não movimenta caixa nem estoque". */
    public function movesMoney(): bool
    {
        return $this === self::SALE;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
