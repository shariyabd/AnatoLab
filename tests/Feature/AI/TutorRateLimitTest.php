<?php

declare(strict_types=1);

use App\Models\User;

/*
| Rate limits ship with the endpoint, not after it (docs/engineering.md §10).
| Numbers from docs/architecture.md §8.4: tutor 20/min and 200/day, hints
| 30/min. Lowered here so the test is three requests rather than twenty-one.
*/

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('throttles the tutor per minute', function (): void {
    config(['ai.limits.tutor_per_minute' => 2]);

    $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'One?'])->assertOk();
    $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'Two?'])->assertOk();
    $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'Three?'])->assertStatus(429);
});

it('throttles the tutor per day across every endpoint', function (): void {
    // A slow drip must still have a ceiling: the per-minute limit alone lets a
    // student spend all day at nineteen questions a minute.
    config(['ai.limits.tutor_per_day' => 2]);

    $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'One?'])->assertOk();
    $this->postJson('/api/v1/ai/tutor/hint', ['question' => 'Two?'])->assertOk();
    $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'Three?'])->assertStatus(429);
});

it('gives hints their own, looser per-minute budget', function (): void {
    config(['ai.limits.tutor_per_minute' => 1, 'ai.limits.hint_per_minute' => 5]);

    $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'One?'])->assertOk();
    $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'Two?'])->assertStatus(429);

    // Hints are cheaper and asked in bursts while a student is stuck.
    $this->postJson('/api/v1/ai/tutor/hint', ['question' => 'Stuck on this?'])->assertOk();
});

it('buckets by user, so one classroom behind one address is not one bucket', function (): void {
    config(['ai.limits.tutor_per_minute' => 1]);

    $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'One?'])->assertOk();
    $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'Two?'])->assertStatus(429);

    $this->actingAs(User::factory()->create());

    $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'Their first?'])->assertOk();
});
