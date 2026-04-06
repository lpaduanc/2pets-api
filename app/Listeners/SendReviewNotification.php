<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\ReviewCreated;
use App\Notifications\InAppNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendReviewNotification implements ShouldQueue
{
    public function handle(ReviewCreated $event): void
    {
        $review = $event->review->load(['client', 'professional']);

        try {
            $professional = $review->professional;
            if ($professional) {
                $stars = str_repeat('★', $review->rating) . str_repeat('☆', 5 - $review->rating);
                $professional->notify(new InAppNotification(
                    type: NotificationType::REVIEW_REQUEST,
                    title: 'Nova avaliacao recebida',
                    body: "{$review->client->name} avaliou voce: {$stars}",
                    data: [
                        'review_id' => $review->id,
                        'rating' => $review->rating,
                    ]
                ));
            }
        } catch (\Exception $e) {
            Log::error('Failed to send review notification', ['error' => $e->getMessage()]);
        }
    }
}
