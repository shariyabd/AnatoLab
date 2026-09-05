<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AI\TutorAskRequest;
use App\Http\Requests\AI\TutorExplainRequest;
use App\Http\Requests\AI\TutorHintRequest;
use App\Http\Resources\AI\TutorReplyResource;
use App\Services\AI\AITutorService;

/**
 * The three tutor endpoints (docs/architecture.md §8.2, PRD §38).
 *
 * Thin by construction: validate through a FormRequest, delegate to the
 * service, return a Resource (invariant 7). All three actions are the same
 * three lines because the pipeline is identical and only the task differs —
 * that is the point of TutorTask.
 *
 * There is no try/catch here. An AIProviderException is logged with a
 * correlation id and rendered as an educational-tone message by
 * bootstrap/app.php; a second error path in this controller would be the one
 * that eventually leaks a provider name (docs/architecture.md §14).
 *
 * Rate limits are declared on the routes, not here, so `route:list` shows them.
 */
final class TutorController extends Controller
{
    public function __construct(private readonly AITutorService $tutor) {}

    public function ask(TutorAskRequest $request): TutorReplyResource
    {
        return new TutorReplyResource(
            $this->tutor->respond($request->student(), $request->toData())
        );
    }

    public function explain(TutorExplainRequest $request): TutorReplyResource
    {
        return new TutorReplyResource(
            $this->tutor->respond($request->student(), $request->toData())
        );
    }

    public function hint(TutorHintRequest $request): TutorReplyResource
    {
        return new TutorReplyResource(
            $this->tutor->respond($request->student(), $request->toData())
        );
    }
}
