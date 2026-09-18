<?php

namespace App\Enums;

/**
 * `payment_methods.direction` — contrato docs/gap-simplesvet/04, que pede explicitamente
 * "distinguir forma de pagamento (saída) de forma de recebimento (entrada) por uma flag
 * `direction` em vez de duas tabelas".
 *
 * Duas tabelas separadas duplicariam taxa, prazo e conta destino para "Pix" — e o Pix é o
 * mesmo Pix nos dois sentidos.
 */
enum PaymentDirection: string
{
    case IN = 'in';
    case OUT = 'out';
    case BOTH = 'both';

    public function label(): string
    {
        return match ($this) {
            self::IN => 'Recebimento',
            self::OUT => 'Pagamento',
            self::BOTH => 'Recebimento e pagamento',
        };
    }

    public function acceptsInbound(): bool
    {
        return $this !== self::OUT;
    }

    public function acceptsOutbound(): bool
    {
        return $this !== self::IN;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
