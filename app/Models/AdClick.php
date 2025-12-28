<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdClick extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ad_campaign_id',
        'ad_impression_id',
        'user_id',
        'clicked_at',
    ];

    protected $casts = [
        'clicked_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    public function impression(): BelongsTo
    {
        return $this->belongsTo(AdImpression::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

