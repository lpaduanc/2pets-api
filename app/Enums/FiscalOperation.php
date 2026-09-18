<?php

namespace App\Enums;

/**
 * `sales.fiscal_operation` — os 6 "Tipos de venda" do SimplesVet, com semântica FISCAL
 * (docs/gap-simplesvet/01-caixa-pdv.md).
 *
 * Não é preferência de UI: a combinação presença × destinatário × transporte determina CFOP,
 * modelo de documento (NFC-e para o consumidor no balcão, NF-e para revenda ou entrega) e
 * destaque de ICMS. O doc 05 (emissão fiscal) consome exatamente esta coluna — por isso ela
 * nasce agora, mesmo com a emissão fora de escopo.
 */
enum FiscalOperation: string
{
    case IN_PERSON_CONSUMER = 'in_person_consumer';
    case IN_PERSON_RESALE = 'in_person_resale';
    case DELIVERY_CONSUMER = 'delivery_consumer';
    case DELIVERY_RESALE = 'delivery_resale';
    case ONLINE_ORDER_CARRIER = 'online_order_carrier';
    case PHONE_ORDER_CARRIER = 'phone_order_carrier';

    public function label(): string
    {
        return match ($this) {
            self::IN_PERSON_CONSUMER => 'Presencial, para consumidor final',
            self::IN_PERSON_RESALE => 'Presencial, para revenda',
            self::DELIVERY_CONSUMER => 'Delivery ou atendimento domiciliar',
            self::DELIVERY_RESALE => 'Delivery para revenda',
            self::ONLINE_ORDER_CARRIER => 'Pedido via internet, envio por transportador',
            self::PHONE_ORDER_CARRIER => 'Pedido via telefone, envio por transportador',
        };
    }

    /** Revenda exige NF-e com o CNPJ do comprador; consumo final aceita NFC-e (doc 05). */
    public function isResale(): bool
    {
        return in_array($this, [self::IN_PERSON_RESALE, self::DELIVERY_RESALE], true);
    }

    /** Operação presencial — a única que o balcão fecha com o cliente na frente. */
    public function isInPerson(): bool
    {
        return in_array($this, [self::IN_PERSON_CONSUMER, self::IN_PERSON_RESALE], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
