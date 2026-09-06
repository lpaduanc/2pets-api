<?php

namespace App\Http\Requests\Message;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Listing of the caller's conversations.
 *
 * `include` is an opt-in expansion: without it the response is byte-for-byte the
 * one clients already consume. With it, the thread of the first conversation
 * comes along, saving the mandatory second round trip on first paint.
 */
class ConversationIndexRequest extends FormRequest
{
    public const INCLUDE_FIRST_CONVERSATION_MESSAGES = 'first_conversation_messages';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'include' => ['sometimes', 'string', Rule::in([self::INCLUDE_FIRST_CONVERSATION_MESSAGES])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'include.in' => 'Expansão inválida. Valor aceito: '.self::INCLUDE_FIRST_CONVERSATION_MESSAGES.'.',
        ];
    }

    public function includesFirstConversationMessages(): bool
    {
        return $this->validated('include') === self::INCLUDE_FIRST_CONVERSATION_MESSAGES;
    }
}
