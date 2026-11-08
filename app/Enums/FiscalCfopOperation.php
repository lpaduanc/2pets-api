<?php

namespace App\Enums;

/**
 * Faixa de CFOP do perfil tributário (`tax_profile_cfops.operation`) — spec
 * docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §3.4. `BONUS` está reservado: a saída de
 * bonificação (5.910/6.910) espera o contador (docs/PENDENCIAS.md) e nenhum fluxo a usa ainda.
 */
enum FiscalCfopOperation: string
{
    case SALE_CONSUMER = 'sale_consumer';
    case SALE_RESALE = 'sale_resale';
    case RETURN_FROM_CUSTOMER = 'return_from_customer';
    case RETURN_TO_SUPPLIER = 'return_to_supplier';
    case BONUS = 'bonus';

    public function label(): string
    {
        return match ($this) {
            self::SALE_CONSUMER => 'Venda a consumidor',
            self::SALE_RESALE => 'Venda para revenda',
            self::RETURN_FROM_CUSTOMER => 'Devolução de cliente',
            self::RETURN_TO_SUPPLIER => 'Devolução ao fornecedor',
            self::BONUS => 'Bonificação (reservado)',
        };
    }

    /**
     * Primeiro dígito válido do CFOP: entrada começa com 1 (dentro do estado) ou 2 (fora);
     * saída com 5 ou 6. Só a devolução de cliente é entrada.
     */
    public function expectedFirstDigit(FiscalCfopScope $scope): string
    {
        $isEntry = $this === self::RETURN_FROM_CUSTOMER;

        return match (true) {
            $isEntry && $scope === FiscalCfopScope::INTRASTATE => '1',
            $isEntry => '2',
            $scope === FiscalCfopScope::INTRASTATE => '5',
            default => '6',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
