<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdImpression extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ad_campaign_id',
        'user_id',
        'ip_address',
        'user_agent',
        'impressed_at',
    ];

    protected $casts = [
        'impressed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

