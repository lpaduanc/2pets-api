<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Surgery extends Model
{
    protected $fillable = [
        'pet_id',
        'professional_id',
        'surgery_date',
        'surgery_type',
        'pre_op_notes',
        'procedure_description',
        'post_op_notes',
        'anesthesia_used',
        'complications',
        'status',
    ];

    protected $casts = [
        'surgery_date' => 'date',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }
}
