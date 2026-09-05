<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConversationContextType;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
final class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'context_type' => ConversationContextType::General,
            'context_id' => null,
            'title' => $this->faker->sentence(4),
        ];
    }

    public function aboutStructure(int $structureId): self
    {
        return $this->state(fn (): array => [
            'context_type' => ConversationContextType::Structure,
            'context_id' => $structureId,
        ]);
    }
}
