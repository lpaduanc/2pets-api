<?php

namespace App\Services\Advertising;

use App\Models\AdCampaign;
use App\Models\AdClick;
use App\Models\AdImpression;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class AdService
{
    private const COST_PER_IMPRESSION = 0.10; // R$ 0.10 per impression
    private const COST_PER_CLICK = 1.00; // R$ 1.00 per click

    public function getFeaturedProfessionals(array $filters = []): Collection
    {
        $activeCampaigns = AdCampaign::where('status', 'active')
            ->where('campaign_type', 'featured_listing')
            ->where('start_date', '<=', now())
            ->where(function ($query) {
                $query->whereNull('end_date')
                      ->orWhere('end_date', '>=', now());
            })
            ->with('professional')
            ->get();

        // Filter by targeting if provided
        if (!empty($filters)) {
            $activeCampaigns = $activeCampaigns->filter(function ($campaign) use ($filters) {
                $targeting = $campaign->targeting ?? [];
                
                foreach ($filters as $key => $value) {
                    if (isset($targeting[$key]) && $targeting[$key] !== $value) {
                        return false;
                    }
                }
                
                return true;
            });
        }

        return $activeCampaigns->pluck('professional')->unique('id');
    }

    public function trackImpression(AdCampaign $campaign, ?User $user, Request $request): AdImpression
    {
        $impression = AdImpression::create([
            'ad_campaign_id' => $campaign->id,
            'user_id' => $user?->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'impressed_at' => now(),
        ]);

        $campaign->incrementSpent(self::COST_PER_IMPRESSION);

        return $impression;
    }

    public function trackClick(AdCampaign $campaign, AdImpression $impression, ?User $user): AdClick
    {
        $click = AdClick::create([
            'ad_campaign_id' => $campaign->id,
            'ad_impression_id' => $impression->id,
            'user_id' => $user?->id,
            'clicked_at' => now(),
        ]);

        $campaign->incrementSpent(self::COST_PER_CLICK);

        return $click;
    }

    public function getCampaignAnalytics(AdCampaign $campaign): array
    {
        $impressions = $campaign->impressions()->count();
        $clicks = $campaign->clicks()->count();
        $ctr = $campaign->getCtr();

        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => round($ctr, 2),
            'spent' => (float) $campaign->total_spent,
            'budget' => (float) $campaign->getTotalBudget(),
            'cost_per_click' => $clicks > 0 ? $campaign->total_spent / $clicks : 0,
            'cost_per_impression' => $impressions > 0 ? $campaign->total_spent / $impressions : 0,
        ];
    }
}

