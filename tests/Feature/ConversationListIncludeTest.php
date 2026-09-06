<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/messages/conversations[?include=first_conversation_messages]
 *
 * The opt-in expansion exists so the messages screen renders a thread on first
 * paint without the mandatory second, id-dependent round trip. The contract of
 * the endpoint without the parameter must stay exactly as it was.
 */
class ConversationListIncludeTest extends TestCase
{
    use RefreshDatabase;

    private const INCLUDE_QUERY = '?include=first_conversation_messages';

    private User $tutor;

    private User $veterinarian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();
        $this->veterinarian = User::factory()->veterinarian()->create();
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/messages/conversations')->assertStatus(401);
    }

    public function test_listing_without_the_include_keeps_the_current_contract(): void
    {
        $conversation = $this->conversationWithMessage($this->veterinarian, 'Olá, tudo bem?', minutesAgo: 5);

        Sanctum::actingAs($this->tutor);

        $this->getJson('/api/messages/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonMissingPath('first_conversation');
    }

    public function test_it_hydrates_the_first_conversation_when_asked(): void
    {
        $otherVeterinarian = User::factory()->veterinarian()->create();
        $older = $this->conversationWithMessage($this->veterinarian, 'Mensagem antiga', minutesAgo: 60);
        $newest = $this->conversationWithMessage($otherVeterinarian, 'Mensagem recente', minutesAgo: 1);

        Sanctum::actingAs($this->tutor);

        $response = $this->getJson('/api/messages/conversations'.self::INCLUDE_QUERY)->assertOk();

        // The hydrated thread is the one the listing shows first.
        $response->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('first_conversation.conversation.id', $newest->id)
            ->assertJsonPath('first_conversation.conversation.other_participant.id', $otherVeterinarian->id)
            ->assertJsonPath('first_conversation.messages.data.0.content', 'Mensagem recente');

        $this->assertNotSame($older->id, $response->json('first_conversation.conversation.id'));
    }

    public function test_the_hydrated_payload_matches_the_single_conversation_endpoint(): void
    {
        $conversation = $this->conversationWithMessage($this->veterinarian, 'Precisa de retorno?', minutesAgo: 2);

        Sanctum::actingAs($this->tutor);

        $fromListing = $this->getJson('/api/messages/conversations'.self::INCLUDE_QUERY)
            ->assertOk()
            ->json('first_conversation.messages.data');

        $fromShow = $this->getJson("/api/messages/conversations/{$conversation->id}")
            ->assertOk()
            ->json('messages.data');

        $this->assertSame(
            array_column($fromShow, 'id'),
            array_column($fromListing, 'id')
        );
    }

    /**
     * A listing is a read. Hydrating the thread must not silently clear the
     * unread badge — the client still asks for that explicitly.
     */
    public function test_hydrating_does_not_mark_messages_as_read(): void
    {
        $this->conversationWithMessage($this->veterinarian, 'Mensagem não lida', minutesAgo: 3);

        Sanctum::actingAs($this->tutor);

        $this->getJson('/api/messages/conversations'.self::INCLUDE_QUERY)->assertOk();

        $this->getJson('/api/messages/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 1);
    }

    public function test_it_returns_a_null_thread_when_there_is_no_conversation(): void
    {
        Sanctum::actingAs($this->tutor);

        $this->getJson('/api/messages/conversations'.self::INCLUDE_QUERY)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('first_conversation', null);
    }

    public function test_it_rejects_an_unknown_include(): void
    {
        Sanctum::actingAs($this->tutor);

        $this->getJson('/api/messages/conversations?include=everything')
            ->assertStatus(422)
            ->assertJsonValidationErrors('include');
    }

    /**
     * `conversations` is unique per participant pair, so each conversation needs
     * its own counterpart.
     */
    private function conversationWithMessage(User $counterpart, string $content, int $minutesAgo): Conversation
    {
        $conversation = Conversation::create([
            'participant_one_id' => $this->tutor->id,
            'participant_two_id' => $counterpart->id,
            'last_message_at' => now()->subMinutes($minutesAgo),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $counterpart->id,
            'content' => $content,
        ]);

        return $conversation;
    }
}
