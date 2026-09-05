<?php

declare(strict_types=1);

namespace App\Http\Requests\Progress;

use App\Enums\LearningEventType;
use App\Models\User;
use App\Services\Progress\AnalyticsService;
use App\Services\Progress\LearningEventData;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a browser may assert about what a student just did (PRD §29).
 *
 * The rule that matters is on `type`: only the four viewer interactions
 * `LearningEventType::clientReportable()` names are accepted. Everything that
 * feeds XP, a badge, or a completion metric — `question_answered`,
 * `lesson_completed`, `mission_completed` — is recorded server-side and
 * rejected here, so a student cannot manufacture the evidence their own
 * progress is computed from.
 *
 * The other rules bound what an unbounded client can send at an append-only
 * table:
 *
 * - **Batch size.** The log grows forever and nothing prunes it in the MVP;
 *   the endpoint is additionally rate limited (`throttle:learning-events`).
 * - **Payload shape.** String values only, five keys, 64 characters each. The
 *   one payload that exists today is `{"layer":"wireframe"}`. Anything looser
 *   is a JSON column a client can write arbitrary structures into.
 * - **Event age.** Batches flush on an interval and on page unload, so a
 *   legitimate event is minutes old at most. A day is generous; older than
 *   that and it is a replayed batch, which would corrupt a streak.
 *
 * Ends at `toData()`: the controller never sees `$request->all()` and the
 * service never sees a Request (invariant 7).
 */
final class RecordEventsRequest extends FormRequest
{
    /**
     * The `auth` middleware plus per-record scoping: every event is filed
     * against the passed-in User, and the payload carries no user field to
     * override it (docs/architecture.md §14).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:'.AnalyticsService::MAX_BATCH],

            'events.*.type' => ['required', 'string', Rule::in(LearningEventType::clientReportable())],

            'events.*.occurredAt' => ['required', 'date', 'after:-1 day', 'before:+5 minutes'],

            // The three things a viewer event can be about. A context the
            // client cannot name is simply omitted; there is no free-text type.
            'events.*.contextType' => ['nullable', 'string', Rule::in(self::CONTEXT_TYPES)],
            'events.*.contextId' => ['nullable', 'integer', 'min:1'],

            'events.*.payload' => ['nullable', 'array', 'max:5'],
            'events.*.payload.*' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'events.*.type.in' => 'That event is recorded by the server, not reported by the browser.',
            'events.*.occurredAt.after' => 'That event is too old to record.',
        ];
    }

    /**
     * @return list<LearningEventData>
     */
    public function toData(): array
    {
        /** @var array{events: list<array<string, mixed>>} $validated */
        $validated = $this->validated();

        $events = [];

        foreach ($validated['events'] as $event) {
            /** @var array<string, mixed>|null $payload */
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : null;

            $contextId = $event['contextId'] ?? null;

            $events[] = new LearningEventData(
                type: LearningEventType::from((string) $event['type']),
                occurredAt: CarbonImmutable::parse((string) $event['occurredAt']),
                contextType: isset($event['contextType']) ? (string) $event['contextType'] : null,
                contextId: is_numeric($contextId) ? (int) $contextId : null,
                payload: $payload === [] ? null : $payload,
            );
        }

        return $events;
    }

    /**
     * The authenticated student, typed.
     *
     * @throws AuthenticationException
     */
    public function student(): User
    {
        $user = $this->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    /**
     * Kept in step with App\Services\Progress\ProgressService::contextLabels(),
     * which is the only reader of these values.
     *
     * @var list<string>
     */
    private const CONTEXT_TYPES = ['organ', 'structure', 'lesson'];
}
