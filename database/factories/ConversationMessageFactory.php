<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationMessage>
 */
final class ConversationMessageFactory extends Factory
{
    protected $model = ConversationMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => MessageRole::User,
            'content' => $this->faker->sentence(),
            'metadata' => null,
        ];
    }

    public function fromAssistant(): self
    {
        return $this->state(fn (): array => [
            'role' => MessageRole::Assistant,
            'metadata' => ['model' => 'null', 'source_ids' => []],
        ]);
    }
}
