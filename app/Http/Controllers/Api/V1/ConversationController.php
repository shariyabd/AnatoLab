<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AI\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AI\ConversationHistory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reading a student's own tutor threads back (PRD §22, acceptance 4).
 *
 * Read-only, so no FormRequest — there is nothing to validate beyond a route
 * parameter the router already constrains to digits.
 *
 * Route model binding is deliberately not used. Binding would load the
 * conversation before anyone had established whose it is, and the ownership
 * check would then be a thing to remember rather than the only way to get the
 * row (docs/architecture.md §14).
 */
final class ConversationController extends Controller
{
    public function __construct(private readonly ConversationHistory $history) {}

    /**
     * @return AnonymousResourceCollection<int, ConversationResource>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ConversationResource::collection(
            $this->history->forUser($this->student($request))
        );
    }

    public function show(Request $request, int $conversation): ConversationResource
    {
        $found = $this->history->transcriptFor($this->student($request), $conversation);

        // 404 for both "does not exist" and "not yours". Distinguishing them
        // would confirm that someone else's conversation id is real.
        if (! $found instanceof Conversation) {
            throw new NotFoundHttpException('No conversation matches that id.');
        }

        return new ConversationResource($found);
    }

    /**
     * @throws AuthenticationException
     */
    private function student(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
