<?php

namespace App\Models;

use App\Enums\PetTransportStatus;
use App\Enums\PetTransportType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Transporte do pet ligado a um agendamento (docs/gap-petflow/PLANO.md, 3.7). */
class PetTransport extends Model
{
    public const RESOURCE_RELATIONS = ['appointment.pet:id,name', 'appointment.client:id,name', 'zone:id,name', 'driver:id,name'];

    protected $fillable = [
        'organization_id', 'professional_id', 'appointment_id', 'service_area_id', 'appointment_service_id',
        'type', 'zip_code', 'address', 'complement', 'city', 'state', 'distance_km', 'fee', 'driver_id', 'status',
        'scheduled_pickup_at', 'on_the_way_at', 'picked_up_at', 'delivered_at', 'failed_at', 'cancelled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => PetTransportType::class,
            'status' => PetTransportStatus::class,
            'distance_km' => 'decimal:2',
            'fee' => 'decimal:2',
            'scheduled_pickup_at' => 'datetime',
            'on_the_way_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class, 'service_area_id')->withTrashed();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function chargeLine(): BelongsTo
    {
        return $this->belongsTo(AppointmentService::class, 'appointment_service_id');
    }
}
