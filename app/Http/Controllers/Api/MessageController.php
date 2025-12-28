<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\Chat\MessagingService;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct(
        private readonly MessagingService $messagingService,
        private readonly FileUploadService $fileUploadService
    ) {}

    public function conversations(Request $request): JsonResponse
    {
        $conversations = $this->messagingService->getConversations($request->user()->id);

        return response()->json(['data' => $conversations]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $conversation = Conversation::findOrFail($id);

        // Verify user is participant
        if (!$conversation->hasParticipant($request->user()->id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $messages = $this->messagingService->getMessages($conversation);

        // Mark as read
        $this->messagingService->markConversationAsRead($conversation, $request->user()->id);

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'other_participant' => $conversation->getOtherParticipant($request->user()->id),
            ],
            'messages' => $messages,
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recipient_id' => 'required|exists:users,id',
            'content' => 'required|string|max:5000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*.file' => 'required|file|max:10240', // 10MB
        ]);

        // Create or find conversation
        $conversation = $this->messagingService->findOrCreateConversation(
            $request->user()->id,
            $validated['recipient_id']
        );

        // Handle attachments if any
        $attachments = null;
        if (isset($validated['attachments'])) {
            $attachments = [];
            foreach ($validated['attachments'] as $attachment) {
                $file = $attachment['file'];
                $path = $this->fileUploadService->uploadFile(
                    $file,
                    'messages',
                    $request->user()->id
                );

                $attachments[] = [
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                ];
            }
        }

        $message = $this->messagingService->sendMessage(
            $conversation,
            $request->user()->id,
            $validated['content'],
            $attachments
        );

        return response()->json([
            'message' => 'Message sent successfully',
            'data' => $message,
        ], 201);
    }

    public function markAsRead(Request $request, int $conversationId): JsonResponse
    {
        $conversation = Conversation::findOrFail($conversationId);

        if (!$conversation->hasParticipant($request->user()->id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->messagingService->markConversationAsRead($conversation, $request->user()->id);

        return response()->json(['message' => 'Messages marked as read']);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->messagingService->getUnreadCount($request->user()->id);

        return response()->json(['unread_count' => $count]);
    }
}

