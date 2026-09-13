<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Vínculo explícito profissional↔cliente, criado quando um profissional cadastra um cliente
 * manualmente (`ClientProvisioningService::provision`). Complementa — nunca substitui — a
 * derivação automática por appointment/invoice/`PetVetAccess` que `ProfessionalClientController`
 * já fazia (ver comentário da migration `create_professional_clients_table`).
 */
class ProfessionalClient extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'professional_id',
        'client_id',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }
}
