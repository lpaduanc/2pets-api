<?php

namespace App\Services\Review;

use App\Models\Professional;
use App\Models\Review;
use Illuminate\Support\Facades\DB;

final class RatingAggregationService
{
    public function updateProfessionalRating(int $professionalId): void
    {
        $stats = Review::where('professional_id', $professionalId)
            ->where('is_visible', true)
            ->select([
                DB::raw('AVG(rating) as average_rating'),
                DB::raw('COUNT(*) as total_reviews'),
            ])
            ->first();

        Professional::where('user_id', $professionalId)->update([
            'average_rating' => round($stats->average_rating ?? 0, 2),
            'total_reviews' => $stats->total_reviews ?? 0,
        ]);
    }

    public function getRatingDistribution(int $professionalId): array
    {
        $distribution = Review::where('professional_id', $professionalId)
            ->where('is_visible', true)
            ->select('rating', DB::raw('COUNT(*) as count'))
            ->groupBy('rating')
            ->orderBy('rating', 'desc')
            ->get()
            ->pluck('count', 'rating')
            ->toArray();

        return [
            5 => $distribution[5] ?? 0,
            4 => $distribution[4] ?? 0,
            3 => $distribution[3] ?? 0,
            2 => $distribution[2] ?? 0,
            1 => $distribution[1] ?? 0,
        ];
    }

    public function getAverageRating(int $professionalId): float
    {
        return Review::where('professional_id', $professionalId)
            ->where('is_visible', true)
            ->avg('rating') ?? 0.0;
    }

    public function getTotalReviews(int $professionalId): int
    {
        return Review::where('professional_id', $professionalId)
            ->where('is_visible', true)
            ->count();
    }
}

