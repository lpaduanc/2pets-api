<?php

namespace App\Services\Review;

use App\Models\Appointment;
use App\Models\Review;
use App\Models\User;
use App\Services\FileUploadService;
use Illuminate\Support\Facades\DB;

final class ReviewService
{
    public function __construct(
        private readonly FileUploadService $fileUploadService,
        private readonly RatingAggregationService $ratingService
    ) {}

    public function createReview(
        User $client,
        int $professionalId,
        int $rating,
        ?string $comment = null,
        ?int $appointmentId = null,
        ?array $photos = []
    ): Review {
        return DB::transaction(function () use (
            $client,
            $professionalId,
            $rating,
            $comment,
            $appointmentId,
            $photos
        ) {
            $this->validateReviewCreation($client->id, $professionalId, $appointmentId);

            $isVerified = $this->isAppointmentCompleted($appointmentId);

            $review = Review::create([
                'professional_id' => $professionalId,
                'client_id' => $client->id,
                'appointment_id' => $appointmentId,
                'rating' => $rating,
                'comment' => $comment,
                'is_verified' => $isVerified,
            ]);

            if ($photos) {
                $this->attachPhotos($review, $photos, $client->id);
            }

            $this->ratingService->updateProfessionalRating($professionalId);

            // TODO: Dispatch ReviewCreated event

            return $review->load(['photos', 'client']);
        });
    }

    public function addResponse(Review $review, User $professional, string $response): void
    {
        if ($review->professional_id !== $professional->id) {
            throw new \Exception('Only the reviewed professional can respond');
        }

        if ($review->hasResponse()) {
            throw new \Exception('Review already has a response');
        }

        $review->response()->create([
            'professional_id' => $professional->id,
            'response' => $response,
        ]);

        // TODO: Notify client about response
    }

    public function flagReview(Review $review, User $user, string $reason): void
    {
        $review->update([
            'is_flagged' => true,
            'flag_reason' => $reason,
        ]);

        // TODO: Notify admin about flagged review
    }

    public function moderateReview(Review $review, bool $approve): void
    {
        $review->update([
            'is_visible' => $approve,
            'is_flagged' => false,
        ]);
    }

    public function toggleHelpful(Review $review, User $user): bool
    {
        $existing = $review->helpfulVotes()
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $review->decrement('helpful_count');
            return false;
        }

        $review->helpfulVotes()->create(['user_id' => $user->id]);
        $review->increment('helpful_count');
        return true;
    }

    public function getProfessionalReviews(int $professionalId, int $perPage = 20)
    {
        return Review::where('professional_id', $professionalId)
            ->where('is_visible', true)
            ->with(['client', 'response', 'photos'])
            ->orderBy('is_verified', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    private function validateReviewCreation(
        int $clientId,
        int $professionalId,
        ?int $appointmentId
    ): void {
        if ($appointmentId) {
            $existingReview = Review::where('appointment_id', $appointmentId)->first();
            if ($existingReview) {
                throw new \Exception('This appointment has already been reviewed');
            }
        }
    }

    private function isAppointmentCompleted(?int $appointmentId): bool
    {
        if (!$appointmentId) {
            return false;
        }

        $appointment = Appointment::find($appointmentId);
        return $appointment && $appointment->status === 'completed';
    }

    private function attachPhotos(Review $review, array $photos, int $userId): void
    {
        foreach ($photos as $photo) {
            $path = $this->fileUploadService->uploadFile(
                $photo,
                'reviews',
                $userId
            );

            $review->photos()->create([
                'file_path' => $path,
                'file_name' => $photo->getClientOriginalName(),
                'file_size' => $photo->getSize(),
            ]);
        }
    }
}

