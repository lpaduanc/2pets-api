<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\Review\RatingAggregationService;
use App\Services\Review\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct(
        private readonly ReviewService $reviewService,
        private readonly RatingAggregationService $ratingService
    ) {}

    public function index(Request $request, int $professionalId): JsonResponse
    {
        $reviews = $this->reviewService->getProfessionalReviews($professionalId);

        $stats = [
            'average_rating' => $this->ratingService->getAverageRating($professionalId),
            'total_reviews' => $this->ratingService->getTotalReviews($professionalId),
            'distribution' => $this->ratingService->getRatingDistribution($professionalId),
        ];

        return response()->json([
            'data' => $reviews,
            'stats' => $stats,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'professional_id' => 'required|exists:users,id',
            'appointment_id' => 'nullable|exists:appointments,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
            'photos' => 'nullable|array|max:5',
            'photos.*' => 'required|image|max:5120', // 5MB
        ]);

        try {
            $review = $this->reviewService->createReview(
                $request->user(),
                $validated['professional_id'],
                $validated['rating'],
                $validated['comment'] ?? null,
                $validated['appointment_id'] ?? null,
                $validated['photos'] ?? []
            );

            return response()->json([
                'message' => 'Review created successfully',
                'data' => $review,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create review',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function addResponse(Request $request, int $reviewId): JsonResponse
    {
        $validated = $request->validate([
            'response' => 'required|string|max:1000',
        ]);

        $review = Review::findOrFail($reviewId);

        try {
            $this->reviewService->addResponse(
                $review,
                $request->user(),
                $validated['response']
            );

            return response()->json(['message' => 'Response added successfully']);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to add response',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function flag(Request $request, int $reviewId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $review = Review::findOrFail($reviewId);

        $this->reviewService->flagReview(
            $review,
            $request->user(),
            $validated['reason']
        );

        return response()->json(['message' => 'Review flagged for moderation']);
    }

    public function toggleHelpful(Request $request, int $reviewId): JsonResponse
    {
        $review = Review::findOrFail($reviewId);

        $isHelpful = $this->reviewService->toggleHelpful($review, $request->user());

        return response()->json([
            'message' => $isHelpful ? 'Marked as helpful' : 'Removed helpful mark',
            'is_helpful' => $isHelpful,
            'helpful_count' => $review->fresh()->helpful_count,
        ]);
    }

    public function moderate(Request $request, int $reviewId): JsonResponse
    {
        $validated = $request->validate([
            'approve' => 'required|boolean',
        ]);

        $review = Review::findOrFail($reviewId);

        $this->reviewService->moderateReview($review, $validated['approve']);

        return response()->json(['message' => 'Review moderated successfully']);
    }
}

