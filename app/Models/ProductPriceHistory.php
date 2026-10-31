<?php

namespace App\Models;

use App\Enums\PriceChangeSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Histórico de preço do produto — docs/gap-petflow/PLANO.md, 1.4. Append-only: cada linha é o
 * preço que passou a valer, com o custo e o markup daquele momento e de onde veio a mudança.
 * Gravado só por `ProductPriceHistoryRecorder`; editar ou apagar uma linha falha de propósito.
 */
class ProductPriceHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'product_price_history';

    protected $fillable = [
        'organization_id',
        'professional_id',
        'product_id',
        'price',
        'cost',
        'markup_percent',
        'user_id',
        'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:4',
            'markup_percent' => 'decimal:4',
            'source' => PriceChangeSource::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Histórico de preço é append-only.'));
        static::deleting(fn (): never => throw new LogicException('Histórico de preço é append-only.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
