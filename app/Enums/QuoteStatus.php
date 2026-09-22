<?php

namespace App\Enums;

/**
 * `sales.quote_status` — ciclo de vida do orçamento (docs/gap-simplesvet/24-orcamentos.md).
 *
 * Só faz sentido quando `sales.kind = quote`; em venda fica `null`.
 *
 * `EXPIRED` quase nunca está GRAVADO: é derivado de `valid_until` na leitura
 * (`Sale::effectiveQuoteStatus()` e `Sale::scopeWhereQuoteStatus()`), para que um orçamento
 * vencido apareça como expirado sem depender de scheduler (critério de aceite do doc 24).
 */
enum QuoteStatus: string
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case VIEWED = 'viewed';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';
    case CONVERTED = 'converted';
    case SUPERSEDED = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::SENT => 'Enviado',
            self::VIEWED => 'Visualizado',
            self::APPROVED => 'Aprovado',
            self::REJECTED => 'Recusado',
            self::EXPIRED => 'Expirado',
            self::CONVERTED => 'Convertido em venda',
            self::SUPERSEDED => 'Substituído',
        };
    }

    /**
     * Só o rascunho aceita item, desconto ou troca de cabeçalho. Depois de enviado, o tutor
     * tem um documento na mão — mudar o valor por baixo dele é o que a revisão (nova versão)
     * existe para evitar.
     */
    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }

    /** Estados em que o tutor ainda pode aprovar ou recusar. */
    public function isAwaitingDecision(): bool
    {
        return in_array($this, [self::SENT, self::VIEWED], true);
    }

    /** Estados que `valid_until` no passado transforma em `EXPIRED` na leitura. */
    public function canExpire(): bool
    {
        return in_array($this, [self::DRAFT, self::SENT, self::VIEWED], true);
    }

    /** @return list<string> */
    public static function expirableValues(): array
    {
        return [self::DRAFT->value, self::SENT->value, self::VIEWED->value];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
