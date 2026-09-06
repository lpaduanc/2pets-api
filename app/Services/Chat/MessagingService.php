<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class MessagingService
{
    public function findOrCreateConversation(int $userId1, int $userId2): Conversation
    {
        // Ensure consistent ordering
        [$participantOne, $participantTwo] = $userId1 < $userId2
            ? [$userId1, $userId2]
            : [$userId2, $userId1];

        return Conversation::firstOrCreate(
            [
                'participant_one_id' => $participantOne,
                'participant_two_id' => $participantTwo,
            ]
        );
    }

    public function sendMessage(
        Conversation $conversation,
        int $senderId,
        string $content,
        ?array $attachments = null
    ): Message {
        return DB::transaction(function () use ($conversation, $senderId, $content, $attachments) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $senderId,
                'content' => $content,
            ]);

            if ($attachments) {
                foreach ($attachments as $attachment) {
                    $message->attachments()->create($attachment);
                }
            }

            $conversation->update(['last_message_at' => now()]);

            // TODO: Dispatch MessageSent event for real-time updates

            return $message->load('attachments', 'sender');
        });
    }

    public function getConversations(int $userId)
    {
        return $this->conversationsOf($userId)
            ->with(['participantOne', 'participantTwo', 'latestMessage'])
            ->get()
            ->map(function ($conversation) use ($userId) {
                $otherParticipant = $conversation->getOtherParticipant($userId);

                return [
                    'id' => $conversation->id,
                    'other_participant' => [
                        'id' => $otherParticipant->id,
                        'name' => $otherParticipant->name,
                        'role' => $otherParticipant->role,
                    ],
                    'latest_message' => $conversation->latestMessage ? [
                        'content' => $conversation->latestMessage->content,
                        'created_at' => $conversation->latestMessage->created_at,
                        'is_mine' => $conversation->latestMessage->sender_id === $userId,
                        'is_read' => $conversation->latestMessage->isRead(),
                    ] : null,
                    'last_message_at' => $conversation->last_message_at,
                ];
            });
    }

    /**
     * Conversations the user takes part in, most recently active first. Single
     * source of ordering so "the first conversation" means the same row for the
     * listing and for the eager-hydrated thread below.
     *
     * @return Builder<Conversation>
     */
    private function conversationsOf(int $userId): Builder
    {
        return Conversation::query()
            ->where(function ($query) use ($userId) {
                $query->where('participant_one_id', $userId)
                    ->orWhere('participant_two_id', $userId);
            })
            ->orderBy('last_message_at', 'desc');
    }

    /**
     * Thread payload of a single conversation, in the exact shape the
     * `GET /messages/conversations/{id}` endpoint returns.
     *
     * Read-only on purpose: marking messages as read stays an explicit, separate
     * act (see markConversationAsRead) so a listing request never mutates state.
     *
     * @return array{conversation: array{id: int, other_participant: ?User}, messages: LengthAwarePaginator}
     */
    public function conversationPayload(Conversation $conversation, int $userId): array
    {
        return [
            'conversation' => [
                'id' => $conversation->id,
                'other_participant' => $conversation->getOtherParticipant($userId),
            ],
            'messages' => $this->getMessages($conversation),
        ];
    }

    /**
     * Thread of the conversation the listing shows first, or null when the user
     * has no conversation at all.
     *
     * @return array{conversation: array{id: int, other_participant: ?User}, messages: LengthAwarePaginator}|null
     */
    public function firstConversationPayload(int $userId): ?array
    {
        $conversation = $this->conversationsOf($userId)->first();

        if ($conversation === null) {
            return null;
        }

        return $this->conversationPayload($conversation, $userId);
    }

    public function getMessages(Conversation $conversation, int $perPage = 50)
    {
        return Message::where('conversation_id', $conversation->id)
            ->with(['sender', 'attachments'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function markConversationAsRead(Conversation $conversation, int $userId): void
    {
        Message::where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function getUnreadCount(int $userId): int
    {
        return Message::whereHas('conversation', function ($query) use ($userId) {
            $query->where('participant_one_id', $userId)
                ->orWhere('participant_two_id', $userId);
        })
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->count();
    }
}
