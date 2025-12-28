<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
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
        return Conversation::where(function ($query) use ($userId) {
            $query->where('participant_one_id', $userId)
                  ->orWhere('participant_two_id', $userId);
        })
        ->with(['participantOne', 'participantTwo', 'latestMessage'])
        ->orderBy('last_message_at', 'desc')
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

