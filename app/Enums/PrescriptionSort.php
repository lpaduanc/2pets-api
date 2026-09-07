<?php

namespace App\Enums;

use Illuminate\Database\Eloquent\Builder;

/**
 * Ordenações da lista de prescrições do profissional (`GET /professional/prescriptions?sort=`).
 *
 * A tela já mandava este parâmetro antes de o servidor entendê-lo, e por isso ordenava no
 * cliente sobre a página carregada — o que só é honesto enquanto a lista inteira couber numa
 * página. Aqui a ordenação é a mesma do banco em qualquer página.
 *
 * Os três valores espelham `PRESCRIPTION_SORT` do app; nomes divergentes quebrariam a tela.
 */
enum PrescriptionSort: string
{
    case RECENT = 'recent';
    case OLDEST = 'oldest';
    case VALIDITY = 'validity';

    /** Parâmetro ausente ou desconhecido cai no padrão de sempre, nunca em 422. */
    public static function fromRequestValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::RECENT;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * O desempate por `id` não é enfeite: `prescription_date` e `valid_until` repetem muito, e
     * sem uma chave única no fim da ordenação o Postgres pode devolver a mesma linha em duas
     * páginas (ou nenhuma vez) entre um `LIMIT/OFFSET` e o seguinte.
     *
     * @param  Builder<\App\Models\Prescription>  $query
     * @return Builder<\App\Models\Prescription>
     */
    public function applyTo(Builder $query): Builder
    {
        return $this->applyPrimaryOrder($query)->orderBy('prescriptions.id', 'desc');
    }

    /**
     * @param  Builder<\App\Models\Prescription>  $query
     * @return Builder<\App\Models\Prescription>
     */
    private function applyPrimaryOrder(Builder $query): Builder
    {
        return match ($this) {
            self::RECENT => $query->orderBy('prescriptions.prescription_date', 'desc'),
            self::OLDEST => $query->orderBy('prescriptions.prescription_date'),
            // `valid_until` é nullable e receita sem prazo não vence: NULL vai para o fim, senão
            // encabeçaria a lista de "vence primeiro" (o padrão do Postgres é NULLS LAST em ASC,
            // mas explicitar impede que uma futura troca para DESC inverta a semântica em silêncio).
            self::VALIDITY => $query->orderByRaw('prescriptions.valid_until asc nulls last'),
        };
    }
}
