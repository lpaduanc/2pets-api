<?php

namespace App\Enums;

use Illuminate\Database\Eloquent\Builder;

/**
 * Estados da lista de prescrições do profissional (`GET /professional/prescriptions?status=`).
 *
 * A tela filtrava no cliente sobre a página já carregada, o que só funciona enquanto a lista
 * inteira couber numa página. Aqui o corte é o mesmo dos scopes do model, uma definição só:
 * "válida" é receita sem data de validade OU com validade a partir de hoje; "vencida" é o
 * complemento exato disso.
 */
enum PrescriptionStatusFilter: string
{
    case ALL = 'all';
    case VALID = 'valid';
    case EXPIRED = 'expired';

    /** Parâmetro ausente ou desconhecido não filtra nada — a lista continua como sempre foi. */
    public static function fromRequestValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::ALL;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @param  Builder<\App\Models\Prescription>  $query
     * @return Builder<\App\Models\Prescription>
     */
    public function applyTo(Builder $query): Builder
    {
        return match ($this) {
            self::ALL => $query,
            self::VALID => $query->valid(),
            self::EXPIRED => $query->expired(),
        };
    }
}
