<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha da ficha técnica do serviço: quanto de um insumo (produto) uma execução consome, na
 * unidade de ESTOQUE do produto — docs/gap-petflow/PLANO.md, 1.3. Sem soft delete: a ficha é
 * cadastro substituído por inteiro no `PUT services/{id}/supplies`; o que foi consumido de fato
 * fica no kardex (`stock_movements` `internal_use`), não aqui.
 */
class ServiceSupply extends Model
{
    protected $fillable = [
        'organization_id',
        'professional_id',
        'service_id',
        'product_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** Com os removidos: produto excluído depois de entrar na ficha continua custando o que custa. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
