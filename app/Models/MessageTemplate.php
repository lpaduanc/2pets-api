<?php

namespace App\Models;

use App\Enums\MessageCategory;
use App\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo de mensagem reutilizável por automação/campanha — contrato
 * `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`. `placeholders` documenta as chaves
 * aceitas em `body` (whitelist), nunca interpoladas livremente — ver `MessageBodyRenderer`.
 */
class MessageTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'name',
        'channel',
        'category',
        'subject',
        'body',
        'whatsapp_template_name',
        'placeholders',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'category' => MessageCategory::class,
            'placeholders' => 'array',
            'active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }
}
