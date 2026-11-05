<?php

namespace App\Enums;

/**
 * Como a categoria entra na DRE GERENCIAL (`financial_categories.dre_treatment`,
 * docs/gap-petflow/PLANO.md, 1.7). Caixa e competência ignoram este campo.
 *
 * A gerencial monta as linhas de venda do snapshot `sale_item_profitability` (receita, desconto,
 * imposto, custo, taxa, comissão); os lançamentos que REPETEM esses valores ficam de fora para
 * não contar duas vezes:
 *  - `inventory_purchase`: compra de mercadoria vira estoque e aparece como CMV na venda;
 *  - `card_fee`: a taxa já vem rateada do recebimento;
 *  - `commission_payout`: a comissão já vem do item;
 *  - `tax`: o imposto sobre vendas já vem do snapshot (alíquota efetiva);
 *  - `ignore`: fora da gerencial (ex.: a receita de venda lançada pelo PDV).
 * `operating` entra como despesa (ou outra receita) operacional; `financial`, no resultado
 * financeiro.
 */
enum DreTreatment: string
{
    case OPERATING = 'operating';
    case INVENTORY_PURCHASE = 'inventory_purchase';
    case CARD_FEE = 'card_fee';
    case COMMISSION_PAYOUT = 'commission_payout';
    case TAX = 'tax';
    case FINANCIAL = 'financial';
    case IGNORE = 'ignore';

    /** Categorias do sistema que não são `operating` (nome exato, como o provisionador cria). */
    private const SYSTEM_DEFAULTS = [
        'revenue' => [
            'Receita de vendas' => self::IGNORE,
            'Produtos' => self::IGNORE,
            'Serviços' => self::IGNORE,
            'Acréscimo de parcelamento' => self::IGNORE,
        ],
        'expense' => [
            'Taxas e tarifas' => self::CARD_FEE,
            'Taxas de cartão' => self::CARD_FEE,
            'Fornecedores' => self::INVENTORY_PURCHASE,
            'Devoluções de vendas' => self::IGNORE,
            'Estorno de acréscimo de parcelamento' => self::IGNORE,
            // PLANO 2.1: a taxa de antecipação é custo financeiro, não operacional.
            'Antecipação de recebíveis' => self::FINANCIAL,
        ],
    ];

    public function label(): string
    {
        return match ($this) {
            self::OPERATING => 'Operacional',
            self::INVENTORY_PURCHASE => 'Compra de mercadoria (vira CMV)',
            self::CARD_FEE => 'Taxa de cartão (já calculada)',
            self::COMMISSION_PAYOUT => 'Pagamento de comissão (já calculada)',
            self::TAX => 'Imposto sobre vendas (já calculado)',
            self::FINANCIAL => 'Resultado financeiro',
            self::IGNORE => 'Fora da DRE gerencial',
        };
    }

    /** Entra na gerencial? (`operating` e `financial`). */
    public function countsInManagerial(): bool
    {
        return $this === self::OPERATING || $this === self::FINANCIAL;
    }

    /** Padrão de uma categoria do sistema pelo nome; qualquer outra nasce `operating`. */
    public static function defaultFor(string $name, FinancialNature $nature): self
    {
        return self::SYSTEM_DEFAULTS[$nature->value][$name] ?? self::OPERATING;
    }

    /** @return array<string, array<string, self>> */
    public static function systemDefaults(): array
    {
        return self::SYSTEM_DEFAULTS;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
